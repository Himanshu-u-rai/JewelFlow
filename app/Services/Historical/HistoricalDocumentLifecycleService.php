<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportRow;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Support\Historical\HistoricalLifecycle;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The ONLY code permitted to move a published historical record.
 *
 * Everything here is record-keeping. Nothing in this file calls
 * InvoiceAccountingService, LedgerService, BusinessIdentifierService,
 * StockService, MetalService, LoyaltyService or any notification path, and
 * nothing writes to `invoices`, `invoice_items`, `shop_counters`,
 * `invoice_number_events`, `cash_transactions`, `invoice_payments`,
 * `metal_movements`, `customer_gold_transactions` or `loyalty_transactions`.
 * Publishing a historical batch changes the row count of exactly three tables:
 * historical_import_batches, historical_sales_documents, historical_import_rows.
 */
class HistoricalDocumentLifecycleService
{
    public function __construct(
        private readonly HistoricalOpeningBalanceEvaluator $openingBalance,
    ) {}

    /**
     * Atomic publish claim. Returns false when another request already took it,
     * so the caller stops instead of double-publishing.
     */
    public function claimForPublishing(HistoricalImportBatch $batch): bool
    {
        $claimed = HistoricalImportBatch::query()
            ->whereKey($batch->getKey())
            ->whereIn('status', HistoricalImportBatch::EDITABLE)
            ->update(['status' => HistoricalImportBatch::STATUS_PUBLISHING, 'updated_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $batch->refresh();

        return true;
    }

    /** Release a failed publish run so the batch is not stranded in `publishing`. */
    public function releaseClaim(HistoricalImportBatch $batch): void
    {
        HistoricalImportBatch::query()
            ->whereKey($batch->getKey())
            ->where('status', HistoricalImportBatch::STATUS_PUBLISHING)
            ->update(['status' => HistoricalImportBatch::STATUS_REVIEW, 'updated_at' => now()]);

        $batch->refresh();
    }

    /**
     * Claim, publish, and release-if-stranded — the self-contained path for a
     * caller that has not already taken the claim (Batch 1 tests, console).
     *
     * The HTTP controller does NOT use this: it owns the claim so it can put
     * releaseClaim() in its own `finally` (Phase 5), which is why the claim and
     * the work are separable below.
     */
    public function publish(HistoricalImportBatch $batch, ?int $actorId = null): HistoricalImportBatch
    {
        if (! $this->claimForPublishing($batch)) {
            throw new LogicException(
                "Historical import batch #{$batch->id} is not claimable for publishing (status: {$batch->status})."
            );
        }

        try {
            return $this->publishClaimed($batch, $actorId);
        } finally {
            // A throw inside the transaction leaves the batch in `publishing`;
            // hand it back to `review` so it is never stranded.
            if ($batch->fresh()?->status === HistoricalImportBatch::STATUS_PUBLISHING) {
                $this->releaseClaim($batch);
            }
        }
    }

    /**
     * The publish WORK, on a batch whose `publishing` claim the caller already
     * holds. Freeze every draft document in the batch. After this the documents
     * are evidence: no hard delete, no financial edit, corrections only via void
     * or supersede.
     *
     * Idempotent by construction: a batch already `published` has no DRAFT
     * documents to move and its counters are recomputed to the same values, so a
     * repeated call produces the same stable result and never a duplicate.
     */
    public function publishClaimed(HistoricalImportBatch $batch, ?int $actorId = null): HistoricalImportBatch
    {
        return HistoricalLifecycle::run(function () use ($batch, $actorId): HistoricalImportBatch {
            return DB::transaction(function () use ($batch, $actorId): HistoricalImportBatch {
                $now = now();

                $documents = HistoricalSalesDocument::query()
                    ->where('historical_import_batch_id', $batch->getKey())
                    ->where('status', HistoricalSalesDocument::STATUS_DRAFT)
                    ->get();

                // Publish-time recheck: the batch-level gate already looked at this,
                // but opening balances can move between "generate preview" and this
                // claimed transaction. Never freeze a HIGH, unresolved overlap.
                foreach ($documents as $document) {
                    if ($this->openingBalance->evaluate($document) === HistoricalOpeningBalanceEvaluator::HIGH
                        && $document->opening_balance_resolution === null) {
                        throw new LogicException(sprintf(
                            'Historical document #%d has an unresolved HIGH opening-balance overlap and cannot be published.',
                            $document->id
                        ));
                    }
                }

                foreach ($documents as $document) {
                    $document->forceFill([
                        'status'       => HistoricalSalesDocument::STATUS_PUBLISHED,
                        'published_at' => $now,
                        'published_by' => $actorId,
                    ])->save();
                }

                $batch->forceFill([
                    'status'          => HistoricalImportBatch::STATUS_PUBLISHED,
                    'published_at'    => $now,
                    'published_by'    => $actorId,
                    'document_count'  => HistoricalSalesDocument::query()
                        ->where('historical_import_batch_id', $batch->getKey())->count(),
                ])->save();

                return $batch->refresh();
            });
        });
    }

    /**
     * Physically undo an UNPUBLISHED batch. The first historical import is always
     * wrong somewhere, so this has to exist — and it has to be impossible after
     * publication, which is why it refuses on `published`.
     */
    public function rollback(HistoricalImportBatch $batch): void
    {
        if ($batch->isPublished()) {
            throw new LogicException(
                "Historical import batch #{$batch->id} is published and cannot be rolled back. "
                . 'Void or supersede the affected documents instead.'
            );
        }

        DB::transaction(function () use ($batch): void {
            // Rows reference documents with ON DELETE NO ACTION — detach first.
            HistoricalImportRow::query()
                ->where('historical_import_batch_id', $batch->getKey())
                ->update(['historical_sales_document_id' => null, 'updated_at' => now()]);

            // Lines cascade with their document at the database level.
            HistoricalSalesDocument::query()
                ->where('historical_import_batch_id', $batch->getKey())
                ->get()
                ->each
                ->delete();

            $batch->delete(); // rows cascade
        });
    }

    /**
     * A published document was imported in error. It is never deleted; it is
     * marked void with an actor, a timestamp and a reason, and it stops occupying
     * its invoice number and content fingerprint so the correct document can be
     * imported.
     */
    public function void(HistoricalSalesDocument $document, int $actorId, string $reason): HistoricalSalesDocument
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new LogicException('Voiding a historical document requires a reason.');
        }

        return HistoricalLifecycle::run(function () use ($document, $actorId, $reason): HistoricalSalesDocument {
            $document->forceFill([
                'status'      => HistoricalSalesDocument::STATUS_VOID,
                'void_reason' => $reason,
                'voided_by'   => $actorId,
                'voided_at'   => now(),
            ])->save();

            return $document->refresh();
        });
    }

    /**
     * A published document was imported with wrong data. The original stays as
     * evidence in `superseded` state and points forward to its replacement; the
     * replacement points back via `revises_document_id`.
     */
    public function supersede(
        HistoricalSalesDocument $original,
        HistoricalSalesDocument $replacement,
        ?int $actorId = null,
    ): HistoricalSalesDocument {
        if ($original->shop_id !== $replacement->shop_id) {
            throw new LogicException('A historical revision must belong to the same shop as the document it replaces.');
        }

        if ($original->getKey() === $replacement->getKey()) {
            throw new LogicException('A historical document cannot supersede itself.');
        }

        // A terminal document has no future. Allowing one to become a replacement
        // lets `superseded_by_document_id` close a loop (A -> B, then B -> A), and
        // "walk forward to the current version" never terminates. The database
        // cannot catch this: both writes are individually legal transitions.
        if (in_array($replacement->status, [
            HistoricalSalesDocument::STATUS_VOID,
            HistoricalSalesDocument::STATUS_SUPERSEDED,
        ], true)) {
            throw new LogicException(sprintf(
                'Historical document #%s is %s and cannot replace document #%s. '
                . 'Import the corrected document as a new record instead.',
                $replacement->getKey(),
                $replacement->status,
                $original->getKey()
            ));
        }

        return HistoricalLifecycle::run(function () use ($original, $replacement, $actorId): HistoricalSalesDocument {
            return DB::transaction(function () use ($original, $replacement, $actorId): HistoricalSalesDocument {
                $now = now();

                if ($replacement->status === HistoricalSalesDocument::STATUS_DRAFT) {
                    $replacement->forceFill([
                        'revises_document_id' => $original->getKey(),
                        'status'              => HistoricalSalesDocument::STATUS_PUBLISHED,
                        'published_at'        => $now,
                        'published_by'        => $actorId,
                    ])->save();
                }

                $original->forceFill([
                    'status'                    => HistoricalSalesDocument::STATUS_SUPERSEDED,
                    'superseded_by_document_id' => $replacement->getKey(),
                ])->save();

                return $original->refresh();
            });
        });
    }

    /**
     * Manual, operator-confirmed customer linking (R1 never auto-links on fuzzy
     * confidence). The immutable `customer_snapshot` is untouched either way, so
     * unlinking never loses what the original bill said.
     *
     * Draft-only: once a document is published/voided/superseded its customer
     * link is frozen along with everything else it represents as evidence — the
     * DB trigger's allowed_cols still permits this column post-publish (it
     * predates this rule), so the guard has to live here.
     */
    public function linkCustomer(HistoricalSalesDocument $document, ?int $customerId): HistoricalSalesDocument
    {
        if ($document->status !== HistoricalSalesDocument::STATUS_DRAFT) {
            throw new LogicException(sprintf(
                'Historical document %d is %s and its customer link is frozen.',
                $document->id,
                $document->status
            ));
        }

        if ($customerId !== null && $customerId !== $document->customer_id) {
            $customer = \App\Models\Customer::withoutTenant()
                ->where('shop_id', $document->shop_id)
                ->find($customerId);

            if ($customer === null) {
                throw new LogicException('The selected customer was not found for this shop.');
            }

            if ($customer->isArchived()) {
                throw new LogicException('This customer is archived. Reactivate the customer before linking.');
            }
        }

        return HistoricalLifecycle::run(function () use ($document, $customerId): HistoricalSalesDocument {
            $document->forceFill(['customer_id' => $customerId]);

            // The overlap question is tied to a specific customer's opening
            // balance. A different (or no) customer makes any prior overlap
            // finding and its resolution stale, so both reset here — the
            // publish-time recheck re-evaluates from scratch regardless.
            $overlaps = $customerId !== null && $this->openingBalance->overlaps($document);
            $document->forceFill([
                'opening_balance_overlap'      => $overlaps,
                'opening_balance_resolution'   => null,
                'opening_balance_resolved_by'  => null,
                'opening_balance_resolved_at'  => null,
            ])->save();

            return $document->refresh();
        });
    }

    /**
     * The operator's explicit, metadata-only answer to a detected opening-balance
     * overlap. It never changes `opening_balance_overlap`, any money field or any
     * balance/ledger — it only records which of the two honest answers applies,
     * so a HIGH overlap can clear the publish gate instead of being silently
     * bypassed. Draft-only, same as the customer link it accompanies.
     *
     * Severity is re-evaluated live, right before the write — never trusts the
     * stored `opening_balance_overlap` boolean, which may be stale. A document
     * with no overlap (NONE) has nothing to resolve; only HIGH and MEDIUM are
     * eligible, matching the DB CHECK added in 2026_09_17_000200 that rejects a
     * resolution on a row where `opening_balance_overlap` is not true.
     */
    public function resolveOpeningBalance(HistoricalSalesDocument $document, string $resolution, int $actorId): HistoricalSalesDocument
    {
        if ($document->status !== HistoricalSalesDocument::STATUS_DRAFT) {
            throw new LogicException(sprintf(
                'Historical document %d is %s and its opening-balance resolution is frozen.',
                $document->id,
                $document->status
            ));
        }

        if (! in_array($resolution, [
            HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED,
            HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE,
        ], true)) {
            throw new LogicException("Invalid opening-balance resolution: {$resolution}");
        }

        if ($this->openingBalance->evaluate($document) === HistoricalOpeningBalanceEvaluator::NONE) {
            throw new LogicException(sprintf(
                'Historical document %d has no opening-balance overlap to resolve.',
                $document->id
            ));
        }

        return HistoricalLifecycle::run(function () use ($document, $resolution, $actorId): HistoricalSalesDocument {
            $document->forceFill([
                'opening_balance_resolution'  => $resolution,
                'opening_balance_resolved_by' => $actorId,
                'opening_balance_resolved_at' => now(),
            ])->save();

            return $document->refresh();
        });
    }

    /**
     * Advisory inventory link. Creates no item, moves no stock, changes no
     * valuation — and leaves `item_snapshot` intact.
     */
    public function linkItem(HistoricalSalesLine $line, ?int $itemId): HistoricalSalesLine
    {
        return HistoricalLifecycle::run(function () use ($line, $itemId): HistoricalSalesLine {
            $line->forceFill(['item_id' => $itemId])->save();

            return $line->refresh();
        });
    }
}
