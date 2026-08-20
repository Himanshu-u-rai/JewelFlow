<?php

namespace App\Http\Controllers\Historical;

use App\Http\Controllers\Controller;
use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalDocumentLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Corrections to PUBLISHED historical records. A published document is evidence
 * and is never edited or deleted — it is voided (with a reason) or superseded by
 * a corrected record, both recorded with an actor and a timestamp.
 *
 * Both models are resolved through the tenant scope, so a document from another
 * shop is 404, and supersede additionally refuses a cross-shop replacement in the
 * service itself.
 */
class HistoricalDocumentController extends Controller
{
    public function __construct(private readonly HistoricalDocumentLifecycleService $lifecycle) {}

    public function void(Request $request, HistoricalSalesDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->lifecycle->void($document, (int) $request->user()->id, $data['reason']);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Document voided. Its number and fingerprint are released.');
    }

    public function supersede(Request $request, HistoricalSalesDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'replacement_id' => ['required', 'integer', 'different:document'],
            'reason'         => ['nullable', 'string', 'max:500'],
        ]);

        // Tenant-scoped lookup: a replacement from another shop is simply not found.
        $replacement = HistoricalSalesDocument::find((int) $data['replacement_id']);

        if ($replacement === null) {
            return back()->with('error', 'The replacement document was not found.');
        }

        try {
            $this->lifecycle->supersede($document, $replacement, (int) $request->user()->id);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Document superseded. The original is kept as evidence.');
    }

    /**
     * Operator-confirmed customer link. The snapshot is never disturbed.
     * Shop membership, archive state, and draft-only eligibility are all
     * revalidated inside the service — never trust a posted customer_id.
     */
    public function linkCustomer(Request $request, HistoricalSalesDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
        ]);

        try {
            $this->lifecycle->linkCustomer($document, $data['customer_id'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Customer link updated.');
    }

    /**
     * Acknowledge this manual bill's warnings from its own page.
     *
     * A thin wrapper over exactly the same batch columns HistoricalImportController
     * ::acknowledgeWarnings() writes — the acknowledgement is a batch-level fact,
     * because the publish gate that consumes it is batch-level. It is restricted to
     * manual batches so acknowledging one document can never speak for the other
     * fifty documents in a file import.
     */
    public function acknowledgeWarnings(Request $request, HistoricalSalesDocument $document): RedirectResponse
    {
        $batch = $this->manualBatchFor($document);

        if ($batch === null) {
            return back()->with('error', 'This document belongs to a file import. Acknowledge its warnings on the batch page.');
        }

        if (! $batch->isEditable()) {
            return back()->with('error', 'This bill is no longer editable.');
        }

        $batch->forceFill([
            'warnings_acknowledged_at' => now(),
            'warnings_acknowledged_by' => (int) $request->user()->id,
        ])->save();

        return back()->with('success', 'Warnings acknowledged.');
    }

    /**
     * Publish this manual bill from its own page.
     *
     * Delegates to the same lifecycle service and the same
     * HistoricalImportBatch::blockedFromPublishing() gate the batch route uses —
     * no publication logic is duplicated here. Restricted to manual batches for the
     * same reason as acknowledge: on a file import, one document is not the unit
     * of publication.
     */
    public function publish(Request $request, HistoricalSalesDocument $document): RedirectResponse
    {
        // Defense in depth — the route already requires it, but publishing must not
        // depend on routing alone.
        $this->authorize('historical.publish');

        $batch = $this->manualBatchFor($document);

        if ($batch === null) {
            return back()->with('error', 'This document belongs to a file import. Publish it from its batch page.');
        }

        if ($batch->isPublished()) {
            // Idempotent: a repeated press is not an error, it is a no-op.
            return back()->with('success', 'This bill is already published.');
        }

        $blocker = $batch->blockedFromPublishing();

        if ($blocker !== null) {
            return back()->with('error', $blocker);
        }

        try {
            $this->lifecycle->publish($batch, (int) $request->user()->id);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'This bill could not be published. Nothing was changed.');
        }

        return back()->with('success', 'Historical bill published.');
    }

    /**
     * The internal manual batch behind a typed bill, or null when this document
     * came from a file import (where the batch page owns the workflow).
     */
    private function manualBatchFor(HistoricalSalesDocument $document): ?HistoricalImportBatch
    {
        $batch = $document->batch;

        return $batch !== null && $batch->isManualBatch() ? $batch : null;
    }

    /**
     * Operator's explicit call on a HIGH (or MEDIUM) opening-balance overlap.
     * This is metadata only — it never touches the customer's actual opening
     * balance, ledger or receivables. Draft-only and re-checked by the
     * service; a published document's resolution is frozen by both the DB
     * trigger's column allow-list and this same guard.
     */
    public function resolveOpeningBalance(Request $request, HistoricalSalesDocument $document): RedirectResponse
    {
        // Defense in depth: this is a publish-control action (it clears a HIGH
        // publish blocker), not an import action — route middleware already
        // enforces this, but the check is repeated here so the guard does not
        // depend solely on routing.
        $this->authorize('historical.publish');

        $data = $request->validate([
            'resolution' => ['required', 'string', Rule::in([
                HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED,
                HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE,
            ])],
        ]);

        try {
            $this->lifecycle->resolveOpeningBalance($document, $data['resolution'], (int) $request->user()->id);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Opening-balance overlap resolved.');
    }
}
