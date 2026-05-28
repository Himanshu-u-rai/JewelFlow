<?php

namespace App\Services\Returns;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\CustomerGoldTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\ReturnedItemDisposition;
use App\Models\ReturnLineItem;
use App\Models\ReturnOrder;
use App\Services\InvoiceAccountingService;
use App\Services\LoyaltyService;
use App\Services\SubscriptionGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Orchestrates the Returns & Exchanges domain. Phase 1 only implements
 * `createFullReturn` — a single transactional operation that:
 *
 *   1. Creates a ReturnOrder header (settled directly for Phase 1).
 *   2. Creates one ReturnLineItem per invoice_item in the original invoice.
 *   3. Marks invoice_items as returned (returned_at + return_line_item_id).
 *   4. Issues a CreditNote covering the full refund amount.
 *   5. Fires compensating accounting entries (cash, customer-gold, metal,
 *      loyalty) tagged against the credit note.
 *   6. Records ReturnedItemDisposition rows with default disposition = restocked,
 *      and restores each item to status='in_stock' (back into for-sale inventory).
 *   7. Marks the original invoice status='cancelled' for backwards-compat
 *      reporting. The `reversed_by_invoice_id` column on invoices stays null
 *      (new model — the link is via CreditNote.return_order_id → ReturnOrder.invoice_id).
 *
 * Phase 1 wedge: InvoiceAccountingService::cancelByReversal delegates here
 * so the existing "Cancel via Reversal" button keeps working. No visible UX
 * change, but new returns are recorded in the credit-note model instead of
 * the legacy mirror-invoice model.
 */
class ReturnService
{
    public function __construct(
        private CreditNoteService $creditNoteService,
        private GoldValuationService $valuation,
        private RefundPolicyResolver $policyResolver,
    ) {}

    /**
     * Phase 2 — process a return of selected invoice items with per-line
     * condition + disposition decisions.
     *
     * $selections shape:
     *   [ invoice_item_id => [
     *       'condition'   => 'good_condition' | 'minor_wear' | 'damaged' | 'non_sellable',
     *       'disposition' => 'restocked' | 'sent_to_melt' | 'sent_to_rework' | 'written_off',
     *       'reason'      => optional per-line text,
     *   ] ]
     *
     * Behaviour:
     *  - If the selection covers every line of the invoice AND none are already
     *    returned AND every disposition = restocked, this falls back to the
     *    full-return path so the credit note matches the invoice header exactly
     *    (handles legacy invoices whose line sums drift from header totals).
     *  - Otherwise: aggregate the CN from selected lines (which is correct for
     *    partial — the cumulative CN total is what the overflow guard enforces).
     *
     * @return ReturnOrder  the settled return order (with creditNote relation)
     */
    // Phase 4 — refund settlement options.
    public const REFUND_SETTLEMENT_CASH         = 'cash';
    public const REFUND_SETTLEMENT_STORE_CREDIT = 'store_credit';

    public function createPartialReturn(
        Invoice $invoice,
        array $selections,
        string $reason,
        int $userId,
        ?ValuationBasis $basis = null,
        string $refundSettlement = self::REFUND_SETTLEMENT_CASH,
        bool $forExchange = false,
        array $lineOverrides = [],
    ): ReturnOrder
    {
        $invoice->refresh();

        if ($invoice->status !== Invoice::STATUS_FINALIZED) {
            throw new LogicException('Only finalized invoices can have items returned.');
        }
        if (empty($selections)) {
            throw new LogicException('Select at least one item to return.');
        }

        SubscriptionGateService::assertShopWritable((int) $invoice->shop_id);
        InvoiceAccountingService::assertShopLockForDate((int) $invoice->shop_id, now()->toDateString());

        // Service-level settlement mode enforcement — the controller also checks
        // this, but the service is the authoritative gate.
        $shopPreferences = $invoice->shop?->preferences;
        $allowedMode = $shopPreferences?->return_settlement_mode ?? 'cash_or_credit';
        if ($allowedMode === 'cash_only' && $refundSettlement !== self::REFUND_SETTLEMENT_CASH) {
            throw new LogicException('Shop policy only allows cash refunds.');
        }
        if ($allowedMode === 'store_credit_only' && $refundSettlement !== self::REFUND_SETTLEMENT_STORE_CREDIT) {
            throw new LogicException('Shop policy only allows store-credit refunds.');
        }

        // Sanity: if selections covers every line and they're all restocked,
        // delegate to the full-return path for header-accurate CN totals.
        $allLineIds = InvoiceItem::where('invoice_id', $invoice->id)
            ->whereNull('returned_at')
            ->pluck('id')
            ->all();
        sort($allLineIds);
        $selIds = array_map('intval', array_keys($selections));
        sort($selIds);
        $allRestock = collect($selections)->every(fn ($s) => ($s['disposition'] ?? 'restocked') === 'restocked');
        if ($selIds === $allLineIds && $allRestock) {
            return $this->createFullReturn($invoice, $reason, $userId, false, $refundSettlement);
        }

        return DB::transaction(function () use ($invoice, $selections, $reason, $userId, $basis, $refundSettlement, $forExchange, $lineOverrides) {
            $invoiceItemIds = array_keys($selections);
            $lines = InvoiceItem::where('invoice_id', $invoice->id)
                ->whereIn('id', $invoiceItemIds)
                ->lockForUpdate()
                ->with('item')
                ->get();

            if ($lines->count() !== count($invoiceItemIds)) {
                throw new LogicException('Selected items do not all belong to this invoice.');
            }
            foreach ($lines as $line) {
                if ($line->returned_at) {
                    $when = \Carbon\Carbon::parse($line->returned_at)->format('d M Y');
                    throw new LogicException(
                        "Item {$line->item?->barcode} was already returned on {$when}"
                    );
                }
            }

            // Load shop policy and build the effective basis for all lines.
            // When a basis is already provided (e.g. today_rate from ExchangeService
            // which already baked in policy deductions), use it directly.
            // When null, build from the shop's return policy settings.
            $shopPreferences = $invoice->shop?->preferences;
            $effectiveBasis  = $basis ?? $this->policyResolver->basisFromPolicy($shopPreferences);

            // Header.
            $returnOrder = ReturnOrder::create([
                'shop_id'            => $invoice->shop_id,
                'invoice_id'         => $invoice->id,
                'customer_id'        => $invoice->customer_id,
                'return_type'        => ReturnOrder::TYPE_CUSTOMER_RETURN,
                'status'             => ReturnOrder::STATUS_DRAFT,
                'reason'             => $reason,
                'created_by_user_id' => $userId,
            ]);

            $totalLoyaltyToReverse = 0;

            foreach ($lines as $line) {
                $sel = $selections[$line->id];
                $condition   = $sel['condition']   ?? ReturnLineItem::CONDITION_GOOD;
                $disposition = $sel['disposition'] ?? ReturnedItemDisposition::DISPOSITION_RESTOCKED;
                $lineReason  = $sel['reason']      ?? null;

                $refundResult   = $this->policyResolver->resolve($line, $invoice, $effectiveBasis, $shopPreferences);
                $isOverridden = array_key_exists($line->id, $lineOverrides);
                $originalResult = $refundResult;
                if ($isOverridden) {
                    $refundResult = $this->applyLineOverride($line, $invoice, $originalResult, $lineOverrides[$line->id]);
                }
                $refundSubtotal = $refundResult->refundSubtotal;
                $refundGst      = $refundResult->refundGst;
                $refundDiscount = $refundResult->refundDiscount;
                $refundRoundOff = $refundResult->refundRoundOff;
                $refundLoyalty  = $refundResult->refundLoyaltyPts;
                $refundTotal    = $refundResult->refundTotal;

                $returnLine = ReturnLineItem::create([
                    'shop_id'            => $invoice->shop_id,
                    'return_order_id'    => $returnOrder->id,
                    'invoice_item_id'    => $line->id,
                    'item_id'            => $line->item_id,
                    'refund_subtotal'    => $refundSubtotal,
                    'refund_gst'         => $refundGst,
                    'refund_discount'    => $refundDiscount,
                    'refund_round_off'   => $refundRoundOff,
                    'refund_loyalty_pts' => $refundLoyalty,
                    'refund_total'       => $refundTotal,
                    'condition'          => $condition,
                    'reason'             => $lineReason,
                    'policy_breakdown'   => $refundResult->breakdown,
                    'is_overridden'      => $isOverridden,
                    'hsn_code'           => $line->hsn_code,
                ]);

                // Phase 2A — snapshot the stone components from the
                // original sold invoice line onto the return line.
                app(\App\Services\StoneSnapshotService::class)
                    ->snapshotForReturnLineItem($returnLine, $line);

                if ($isOverridden) {
                    \App\Models\AuditLog::create([
                        'shop_id'     => $invoice->shop_id,
                        'user_id'     => $userId,
                        'action'      => 'return_refund_overridden',
                        'model_type'  => 'return_line_item',
                        'model_id'    => $returnLine->id,
                        'description' => "Line #{$returnLine->id} refund overridden on return. Mode: {$lineOverrides[$line->id]['mode']}.",
                        'data'        => [
                            'return_order_id'         => $returnOrder->id,
                            'invoice_item_id'         => $line->id,
                            'override_mode'           => $lineOverrides[$line->id]['mode'],
                            'override_by_user_id'     => $lineOverrides[$line->id]['override_by_user_id'] ?? $userId,
                            'original_refund_total'   => $originalResult->refundTotal,
                            'negotiated_refund_total' => $refundResult->refundTotal,
                            'reason'                  => $lineOverrides[$line->id]['override_reason'] ?? '',
                        ],
                    ]);
                }

                $line->forceFill([
                    'returned_at'         => now(),
                    'return_line_item_id' => $returnLine->id,
                ])->save();

                $this->applyDisposition($returnLine, $disposition, $userId);
                $totalLoyaltyToReverse += $refundLoyalty;
            }

            // Credit note — aggregated from selected lines (CreditNoteService::issue
            // does the invariant + aggregation check; the DB overflow trigger is
            // the final backstop).
            $returnOrder->refresh()->load('lineItems', 'invoice');
            $creditNote = $this->creditNoteService->issue($returnOrder, $userId);

            // Phase 4: refund settlement branches here.
            //  - cash         → existing CashTransaction `out` for the CN amount
            //  - store_credit → no cash entry; credit the customer's wallet
            //                   with the CN amount via StoreCreditService
            // When $forExchange = true, skip both branches — the exchange service
            // handles the offsetting entries itself.
            if (!$forExchange) {
                if ($refundSettlement === self::REFUND_SETTLEMENT_STORE_CREDIT) {
                    if (!$invoice->customer_id) {
                        throw new LogicException('Cannot settle to store credit: invoice has no customer.');
                    }
                    app(\App\Services\StoreCreditService::class)
                        ->creditFromCreditNote($creditNote, $userId);
                } else {
                    $this->fireCompensatingEntriesForLines($invoice, $creditNote, $userId, $lines);
                }
            }

            $returnOrder->update([
                'status'             => ReturnOrder::STATUS_SETTLED,
                'settled_by_user_id' => $userId,
                'settled_at'         => now(),
            ]);

            // If every line on the invoice is now returned, also flip invoice to cancelled
            // for legacy-report compatibility. Otherwise leave it finalized — partial
            // returns don't cancel the original.
            $totalLines    = InvoiceItem::where('invoice_id', $invoice->id)->count();
            $returnedLines = InvoiceItem::where('invoice_id', $invoice->id)->whereNotNull('returned_at')->count();
            $beforeInvoice = DB::table('invoices')->where('id', $invoice->id)->first();
            if ($totalLines > 0 && $totalLines === $returnedLines) {
                DB::table('invoices')->where('id', $invoice->id)->update([
                    'status'              => Invoice::STATUS_CANCELLED,
                    'cancelled_at'        => now(),
                    'cancelled_by'        => $userId,
                    'cancellation_reason' => $reason,
                    'updated_at'          => now(),
                ]);
            }

            $this->writeAuditLog($invoice, $returnOrder, $creditNote, $beforeInvoice, $reason);

            // Partial-aware loyalty reversal: deduct exactly the points that were
            // allocated to the returned lines, rather than the whole invoice's earn.
            try {
                (new LoyaltyService())->reversePartialPoints((int) $invoice->id, (int) $invoice->shop_id, $totalLoyaltyToReverse);
            } catch (\Throwable $e) {
                Log::warning('Partial loyalty reversal failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
            }

            return $returnOrder->refresh()->load('creditNote', 'lineItems');
        });
    }

    /**
     * Process a full return of every line on the given invoice.
     *
     * @return ReturnOrder  the settled return order (with creditNote relation)
     */
    public function createFullReturn(
        Invoice $invoice,
        string $reason,
        int $userId,
        bool $skipPolicy = false,
        string $refundSettlement = self::REFUND_SETTLEMENT_CASH,
    ): ReturnOrder {
        $invoice->refresh();

        if ($invoice->status !== Invoice::STATUS_FINALIZED) {
            throw new LogicException('Only finalized invoices can be returned.');
        }

        // Block duplicate full returns — service-level guard. The DB credit-note
        // overflow trigger also catches this, but a friendlier message helps the
        // UI surface the real problem.
        $existingReturn = ReturnOrder::where('invoice_id', $invoice->id)
            ->where('status', ReturnOrder::STATUS_SETTLED)
            ->first();
        if ($existingReturn) {
            throw new LogicException(
                "Invoice {$invoice->invoice_number} is already returned (return order #{$existingReturn->id})."
            );
        }

        SubscriptionGateService::assertShopWritable((int) $invoice->shop_id);
        InvoiceAccountingService::assertShopLockForDate((int) $invoice->shop_id, now()->toDateString());

        return DB::transaction(function () use ($invoice, $reason, $userId, $skipPolicy, $refundSettlement) {
            // 1. Header in draft → we'll flip to settled at the end.
            $returnOrder = ReturnOrder::create([
                'shop_id'            => $invoice->shop_id,
                'invoice_id'         => $invoice->id,
                'customer_id'        => $invoice->customer_id,
                'return_type'        => ReturnOrder::TYPE_CUSTOMER_RETURN,
                'status'             => ReturnOrder::STATUS_DRAFT,
                'reason'             => $reason,
                'created_by_user_id' => $userId,
            ]);

            // 2. Line items. Each invoice_item becomes a return_line_item with
            //    its locked allocations copied across as the refund snapshot.
            $invoiceItems = InvoiceItem::where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->with('item')
                ->get();
            $shopPreferences = $skipPolicy ? null : $invoice->shop?->preferences;
            foreach ($invoiceItems as $line) {
                /** @var InvoiceItem $line */
                if ($skipPolicy) {
                    $refundSubtotal  = (float) $line->line_total;
                    $refundGst       = (float) $line->gst_amount;
                    $refundDiscount  = (float) $line->allocated_discount;
                    $refundRoundOff  = (float) $line->allocated_round_off;
                    $refundLoyalty   = (int)   $line->allocated_loyalty_pts;
                    $refundTotal     = round($refundSubtotal + $refundGst - $refundDiscount + $refundRoundOff, 2);
                    $policyBreakdown = null;
                } else {
                    $basis           = $this->policyResolver->basisFromPolicy($shopPreferences);
                    $refundResult    = $this->policyResolver->resolve($line, $invoice, $basis, $shopPreferences);
                    $refundSubtotal  = $refundResult->refundSubtotal;
                    $refundGst       = $refundResult->refundGst;
                    $refundDiscount  = $refundResult->refundDiscount;
                    $refundRoundOff  = $refundResult->refundRoundOff;
                    $refundLoyalty   = $refundResult->refundLoyaltyPts;
                    $refundTotal     = $refundResult->refundTotal;
                    $policyBreakdown = $refundResult->breakdown;
                }

                $returnLine = ReturnLineItem::create([
                    'shop_id'            => $invoice->shop_id,
                    'return_order_id'    => $returnOrder->id,
                    'invoice_item_id'    => $line->id,
                    'item_id'            => $line->item_id,
                    'refund_subtotal'    => $refundSubtotal,
                    'refund_gst'         => $refundGst,
                    'refund_discount'    => $refundDiscount,
                    'refund_round_off'   => $refundRoundOff,
                    'refund_loyalty_pts' => $refundLoyalty,
                    'refund_total'       => $refundTotal,
                    'condition'          => ReturnLineItem::CONDITION_GOOD,
                    'reason'             => null,
                    'policy_breakdown'   => $policyBreakdown,
                    'hsn_code'           => $line->hsn_code,
                ]);

                // 3. Mark the invoice_item as returned. The DB trigger we
                //    upgraded in M7 permits writes to these columns post-finalize.
                //    forceFill bypasses InvoiceItem's `$guarded = ['*']` blanket.
                $line->forceFill([
                    'returned_at'         => now(),
                    'return_line_item_id' => $returnLine->id,
                ])->save();
            }

            // 4. Issue the credit note.
            //    skipPolicy=true (void): copy invoice header verbatim — 100% refund.
            //    skipPolicy=false (real return): aggregate from policy-adjusted line
            //    refund_totals so deductions (making charges, GST retention, etc.)
            //    are reflected in the CN. CreditNoteService::issue handles legacy
            //    drift between line-sums and header totals automatically.
            $returnOrder->refresh()->load('lineItems', 'invoice');
            if ($skipPolicy) {
                $creditNote = $this->issueCreditNoteFromInvoiceHeader($returnOrder, $invoice, $userId);
            } else {
                $creditNote = $this->creditNoteService->issue($returnOrder, $userId);
            }

            // 5. Compensating entries — same accounting shape as the legacy
            //    cancelByReversal, but tagged against the credit note via
            //    source_type/reference_type instead of a mirror invoice.
            //    When settling to store credit, credit the customer's wallet
            //    instead of firing a cash-out entry.
            if ($refundSettlement === self::REFUND_SETTLEMENT_STORE_CREDIT && $invoice->customer_id) {
                app(\App\Services\StoreCreditService::class)->creditFromCreditNote($creditNote, $userId);
            } else {
                $this->fireCompensatingEntries($invoice, $creditNote, $userId);
            }

            // 6. Inventory restoration with default disposition = restocked.
            $this->restockReturnedItems($invoice, $returnOrder, $userId);

            // 7. Transition the return order to settled (immutable from here).
            $returnOrder->update([
                'status'             => ReturnOrder::STATUS_SETTLED,
                'settled_by_user_id' => $userId,
                'settled_at'         => now(),
            ]);

            // 8. Mark the original invoice as cancelled (legacy reporting
            //    contract). reversed_by_invoice_id stays null — new model
            //    links via credit_notes.return_order_id.
            $beforeInvoice = DB::table('invoices')->where('id', $invoice->id)->first();
            DB::table('invoices')
                ->where('id', $invoice->id)
                ->update([
                    'status'              => Invoice::STATUS_CANCELLED,
                    'cancelled_at'        => now(),
                    'cancelled_by'        => $userId,
                    'cancellation_reason' => $reason,
                    'updated_at'          => now(),
                ]);

            $this->writeAuditLog($invoice, $returnOrder, $creditNote, $beforeInvoice, $reason);

            // 9. Loyalty reversal — non-fatal; failures get logged but don't
            //    roll back the return (matches legacy cancelByReversal behaviour).
            try {
                (new LoyaltyService())->reversePoints((int) $invoice->id, (int) $invoice->shop_id);
            } catch (\Throwable $e) {
                Log::warning('Loyalty reversal failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
            }

            return $returnOrder->refresh()->load('creditNote', 'lineItems');
        });
    }

    /**
     * Create a ReturnOrder in pending_approval status, storing the selections
     * for later processing when a manager approves.
     */
    public function createPendingApproval(
        Invoice $invoice,
        array $selections,
        string $reason,
        int $userId,
        string $refundSettlement = self::REFUND_SETTLEMENT_CASH,
        array $lineOverrides = [],
    ): ReturnOrder {
        if ($invoice->status !== Invoice::STATUS_FINALIZED) {
            throw new LogicException('Only finalized invoices can have items returned.');
        }
        SubscriptionGateService::assertShopWritable((int) $invoice->shop_id);
        InvoiceAccountingService::assertShopLockForDate((int) $invoice->shop_id, now()->toDateString());

        $returnOrder = new ReturnOrder();
        $returnOrder->forceFill([
            'shop_id'            => $invoice->shop_id,
            'invoice_id'         => $invoice->id,
            'customer_id'        => $invoice->customer_id,
            'return_type'        => ReturnOrder::TYPE_CUSTOMER_RETURN,
            'status'             => ReturnOrder::STATUS_PENDING_APPROVAL,
            'reason'             => $reason,
            'refund_settlement'  => $refundSettlement,
            'pending_data'       => ['selections' => $selections, 'reason' => $reason, 'line_overrides' => $lineOverrides],
            'created_by_user_id' => $userId,
        ])->save();

        return $returnOrder;
    }

    public function redisposeItem(
        ReturnLineItem $returnLine,
        string $disposition,
        int $userId,
        ?string $notes = null,
    ): void {
        $previousDisposition = null;
        $wasApplied          = false;

        DB::transaction(function () use ($returnLine, $disposition, $userId, $notes, &$previousDisposition, &$wasApplied) {
            // Re-lock the return line to prevent concurrent double-submit.
            $locked = ReturnLineItem::where('id', $returnLine->id)
                ->where('shop_id', $returnLine->shop_id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new LogicException('Return line item not found.');
            }

            // Idempotency: if a non-pending disposition already exists for this
            // return line, reject the request rather than creating a duplicate row.
            $latest = ReturnedItemDisposition::where('return_line_item_id', $locked->id)
                ->latest('dispositioned_at')
                ->first();

            if ($latest && $latest->disposition === $disposition) {
                // Same disposition already recorded — silently succeed (idempotent).
                return;
            }

            $previousDisposition = $latest?->disposition;
            $wasApplied          = true;

            $this->applyDisposition($locked, $disposition, $userId, $notes);
        });

        // Audit entry outside the transaction — skipped on idempotent no-ops.
        if ($wasApplied) {
            AuditLog::create([
                'shop_id'     => $returnLine->shop_id,
                'user_id'     => $userId,
                'action'      => 'item_re_dispositioned',
                'model_type'  => 'return_line_item',
                'model_id'    => $returnLine->id,
                'description' => "Item disposition changed"
                    . ($previousDisposition ? " from '{$previousDisposition}'" : '')
                    . " to '{$disposition}'.",
                'data'        => [
                    'return_line_item_id'  => $returnLine->id,
                    'item_id'              => $returnLine->item_id,
                    'previous_disposition' => $previousDisposition,
                    'new_disposition'      => $disposition,
                    'notes'                => $notes,
                ],
            ]);
        }
    }

    /**
     * Approve a pending return — transitions to settled and fires all accounting entries.
     */
    public function approveReturn(ReturnOrder $returnOrder, int $approverId, array $approverLineOverrides = []): ReturnOrder
    {
        if ($returnOrder->status !== ReturnOrder::STATUS_PENDING_APPROVAL) {
            throw new LogicException('Only pending-approval returns can be approved.');
        }
        $invoice    = Invoice::findOrFail($returnOrder->invoice_id);
        $data       = $returnOrder->pending_data ?? [];
        $selections = $data['selections'] ?? [];
        $reason     = $data['reason'] ?? $returnOrder->reason ?? '';
        $refundSettlement = $returnOrder->refund_settlement ?? self::REFUND_SETTLEMENT_CASH;

        $lineOverrides = !empty($approverLineOverrides)
            ? $approverLineOverrides
            : ($data['line_overrides'] ?? []);

        // Cancel the pending header — createPartialReturn will create a new settled one.
        DB::table('return_orders')->where('id', $returnOrder->id)->update([
            'status'              => ReturnOrder::STATUS_CANCELLED,
            'cancellation_reason' => 'Superseded by approval-triggered return.',
            'updated_at'          => now(),
        ]);

        return $this->createPartialReturn(
            $invoice,
            $selections,
            $reason,
            $approverId,
            null,
            $refundSettlement,
            false,
            $lineOverrides,
        );
    }

    /**
     * Reject a pending return — transitions to cancelled.
     */
    public function rejectReturn(ReturnOrder $returnOrder, int $approverId, string $reason): void
    {
        if ($returnOrder->status !== ReturnOrder::STATUS_PENDING_APPROVAL) {
            throw new LogicException('Only pending-approval returns can be rejected.');
        }
        DB::table('return_orders')->where('id', $returnOrder->id)->update([
            'status'              => ReturnOrder::STATUS_CANCELLED,
            'cancellation_reason' => 'Rejected by approver: ' . $reason,
            'updated_at'          => now(),
        ]);
        try {
            AuditLog::create([
                'shop_id'     => $returnOrder->shop_id,
                'action'      => 'return_approval_rejected',
                'model_type'  => 'return_order',
                'model_id'    => $returnOrder->id,
                'description' => "Return #{$returnOrder->id} rejected by approver #{$approverId}: {$reason}",
                'data'        => ['approver_id' => $approverId, 'reason' => $reason],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Return rejection audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * For full returns, emit a credit note whose totals are the invoice's
     * header values negated-from-the-customer's-perspective (i.e. expressed
     * as positive credit amounts). Bypasses CreditNoteService::issue which
     * aggregates from line items — necessary because legacy invoices can
     * have small drift between SUM(invoice_items.*) and invoice.* headers,
     * and the overflow guard requires exact-match against the invoice total.
     */
    private function issueCreditNoteFromInvoiceHeader(ReturnOrder $returnOrder, Invoice $invoice, int $userId)
    {
        $identity = \App\Services\BusinessIdentifierService::nextCreditNoteIdentifier((int) $invoice->shop_id);

        $cn = new \App\Models\CreditNote();
        $cn->forceFill([
            'shop_id'              => $invoice->shop_id,
            'return_order_id'      => $returnOrder->id,
            'invoice_id'           => $invoice->id,
            'customer_id'          => $invoice->customer_id,
            'credit_note_sequence' => $identity['sequence'],
            'credit_note_number'   => $identity['number'],
            'subtotal'             => round((float) $invoice->subtotal,        2),
            'gst'                  => round((float) $invoice->gst,             2),
            'gst_rate'             => $invoice->gst_rate,
            'wastage_charge'       => round((float) $invoice->wastage_charge,  2),
            'discount'             => round((float) $invoice->discount,        2),
            'round_off'            => round((float) $invoice->round_off,       4),
            'total'                => round((float) $invoice->total,           2),
            'status'               => \App\Models\CreditNote::STATUS_ISSUED,
            'issued_at'            => now(),
            'issued_by_user_id'    => $userId,
            'reason'               => $returnOrder->reason,
        ])->save();

        return $cn->refresh();
    }

    /**
     * Compensating cash + customer-gold + metal-lot entries. Tagged against
     * the credit note via source_type/reference_type; invoice_id continues
     * to reference the original invoice for backward-compatible reporting.
     */
    private function fireCompensatingEntries(Invoice $invoice, $creditNote, int $userId): void
    {
        // Cash refund out.
        CashTransaction::record([
            'shop_id'        => $invoice->shop_id,
            'user_id'        => $userId,
            'type'           => 'out',
            'amount'         => abs((float) $invoice->total),
            'source_type'    => 'credit_note',
            'source_id'      => $creditNote->id,
            'invoice_id'     => $invoice->id,
            'description'    => "Refund for {$invoice->invoice_number} via credit note {$creditNote->credit_note_number}",
            'reference_type' => 'credit_note',
            'reference_id'   => $creditNote->id,
        ]);

        // Mirror customer-gold deposits (if customer paid with old gold).
        $goldRows = CustomerGoldTransaction::query()
            ->where('shop_id', $invoice->shop_id)
            ->where('invoice_id', $invoice->id)
            ->where('type', '!=', 'credit_note_reversal') // don't double-reverse
            ->get();

        foreach ($goldRows as $row) {
            CustomerGoldTransaction::record([
                'shop_id'        => $row->shop_id,
                'customer_id'    => $row->customer_id,
                'gross_weight'   => $row->gross_weight,
                'purity'         => $row->purity,
                'fine_gold'      => -1 * (float) $row->fine_gold,
                'type'           => 'credit_note_reversal',
                'reference_type' => 'credit_note',
                'reference_id'   => $creditNote->id,
                'invoice_id'     => $invoice->id,
            ]);
        }

        // Metal-lot inventory rollback for manufactured items.
        $invoice->load('items.item');
        foreach ($invoice->items as $line) {
            $item = $line->item;
            if (!$item) {
                continue;
            }
            if (!$item->metal_lot_id || $item->source === 'job_work') {
                continue;
            }

            $fineMultiplier = \App\Services\MetalRegistry::fineWeightMultiplier((string) $item->metal_type, (float) $item->purity);
            if ($fineMultiplier === null) {
                throw new \LogicException("Cannot derive fine weight for non-accounting metal '{$item->metal_type}'.");
            }
            $fineWeight = (float) $line->weight * $fineMultiplier;

            $lot = MetalLot::where('shop_id', $invoice->shop_id)
                ->where('id', $item->metal_lot_id)
                ->lockForUpdate()
                ->first();
            if ($lot) {
                $lot->fine_weight_remaining = (float) $lot->fine_weight_remaining + $fineWeight;
                $lot->save();
            }

            MetalMovement::record([
                'shop_id'        => $invoice->shop_id,
                'from_lot_id'    => null,
                'to_lot_id'      => $item->metal_lot_id,
                'fine_weight'    => $fineWeight,
                'type'           => 'credit_note_reversal',
                'reference_type' => 'credit_note',
                'reference_id'   => $creditNote->id,
                'invoice_id'     => $invoice->id,
                'user_id'        => $userId,
            ]);
        }

        // Reverse old-metal-in (customer-supplied gold/silver against the sale).
        $oldMetalIn = MetalMovement::where('invoice_id', $invoice->id)
            ->where('type', 'old_metal_in')
            ->get();

        foreach ($oldMetalIn as $mm) {
            if ($mm->to_lot_id) {
                $lot = MetalLot::where('shop_id', $invoice->shop_id)
                    ->where('id', $mm->to_lot_id)
                    ->lockForUpdate()
                    ->first();
                if ($lot) {
                    $lot->fine_weight_remaining = (float) $lot->fine_weight_remaining - (float) $mm->fine_weight;
                    $lot->save();
                }
            }

            MetalMovement::record([
                'shop_id'        => $invoice->shop_id,
                'from_lot_id'    => $mm->to_lot_id,
                'to_lot_id'      => null,
                'fine_weight'    => (float) $mm->fine_weight,
                'type'           => 'old_metal_in_reversal_via_credit_note',
                'reference_type' => 'credit_note',
                'reference_id'   => $creditNote->id,
                'invoice_id'     => $invoice->id,
                'user_id'        => $userId,
            ]);
        }
    }

    /**
     * Phase 1 default disposition for every line = restocked. Each returned
     * item flips back to status=in_stock (available for sale) and a
     * disposition row records the decision. The cached items.return_disposition
     * column is updated by the trigger we added in M5.
     */
    private function restockReturnedItems(Invoice $invoice, ReturnOrder $returnOrder, int $userId): void
    {
        foreach ($returnOrder->lineItems as $returnLine) {
            if (!$returnLine->item_id) {
                continue;
            }
            $this->applyDisposition($returnLine, ReturnedItemDisposition::DISPOSITION_RESTOCKED, $userId);
        }
    }

    private function applyLineOverride(
        InvoiceItem $line,
        Invoice $invoice,
        RefundBreakdown $original,
        array $override,
    ): RefundBreakdown {
        $reason = trim($override['override_reason'] ?? '');
        if (strlen($reason) < 5) {
            throw new LogicException('Override reason is required and must be at least 5 characters.');
        }

        $overrideAt     = now()->toISOString();
        $overrideUserId = $override['override_by_user_id'] ?? 0;

        if ($override['mode'] === 'manual') {
            $manualTotal    = (float) ($override['override_manual_total'] ?? 0);
            $maxRefundable  = (float) $line->line_total
                            + (float) $line->gst_amount
                            - (float) $line->allocated_discount
                            + (float) $line->allocated_round_off;
            if ($manualTotal > $maxRefundable + 0.005) {
                throw new LogicException(
                    "Manual override total ₹{$manualTotal} exceeds maximum refundable amount of ₹{$maxRefundable} for this line."
                );
            }

            $refundDiscount  = $original->refundDiscount;
            $refundRoundOff  = $original->refundRoundOff;
            $refundTotal     = max(round($manualTotal - $refundDiscount + $refundRoundOff, 2), 0.0);

            $gstRate        = (float) ($line->gst_rate ?? $invoice->gst_rate ?? 0);
            $refundGst      = $gstRate > 0 ? round($manualTotal * $gstRate / (100 + $gstRate), 2) : 0.0;
            $refundSubtotal = round($manualTotal - $refundGst, 2);

            $extendedBreakdown = array_merge($original->breakdown, [
                'override_applied'       => true,
                'override_mode'          => 'manual',
                'override_reason'        => $reason,
                'override_by_user_id'    => $overrideUserId,
                'override_at'            => $overrideAt,
                'original_policy_result' => [
                    'refund_subtotal' => $original->refundSubtotal,
                    'refund_gst'      => $original->refundGst,
                    'refund_total'    => $original->refundTotal,
                ],
                'negotiated_result'      => [
                    'manual_total' => $manualTotal,
                    'refund_total' => $refundTotal,
                ],
            ]);

            return new RefundBreakdown(
                refundSubtotal:   $refundSubtotal,
                refundGst:        $refundGst,
                refundDiscount:   $refundDiscount,
                refundRoundOff:   $refundRoundOff,
                refundLoyaltyPts: $original->refundLoyaltyPts,
                refundTotal:      $refundTotal,
                breakdown:        $extendedBreakdown,
            );
        }

        $shopPreferences = $invoice->shop?->preferences;
        $baseDeductions  = $shopPreferences ? $shopPreferences->toRefundDeductions() : [];

        if (isset($override['override_making_charges'])) {
            $baseDeductions['making_charges_refundable'] = (bool) $override['override_making_charges'];
        }
        if (isset($override['override_stone_charges'])) {
            $baseDeductions['stone_charges_refundable'] = (bool) $override['override_stone_charges'];
        }

        $wearLossPct = isset($override['override_wear_loss_pct'])
            ? (float) min(max($override['override_wear_loss_pct'], 0), 25)
            : ($shopPreferences?->wear_loss_pct ?? 0.0);

        $modifiedBasis = ValuationBasis::saleDayRate(
            deductions:                 $baseDeductions,
            wearLossPct:                $wearLossPct,
            refundGstOverride:          isset($override['override_gst']) ? (bool) $override['override_gst'] : null,
            restockingFeeOverridePct:   isset($override['override_waive_restocking']) && $override['override_waive_restocking']
                                            ? 0.0
                                            : null,
        );

        $newResult = $this->policyResolver->resolve($line, $invoice, $modifiedBasis, $shopPreferences);

        $extendedBreakdown = array_merge($newResult->breakdown, [
            'override_applied'       => true,
            'override_mode'          => 'component',
            'override_reason'        => $reason,
            'override_by_user_id'    => $overrideUserId,
            'override_at'            => $overrideAt,
            'original_policy_result' => [
                'refund_subtotal'       => $original->refundSubtotal,
                'refund_gst'            => $original->refundGst,
                'refund_total'          => $original->refundTotal,
                'restocking_fee_amount' => $original->breakdown['restocking_fee_amount'] ?? 0,
                'wear_loss_amount'      => $original->breakdown['wear_loss_amount'] ?? 0,
            ],
            'negotiated_result'      => [
                'refund_subtotal'       => $newResult->refundSubtotal,
                'refund_gst'            => $newResult->refundGst,
                'refund_total'          => $newResult->refundTotal,
                'restocking_fee_waived' => isset($override['override_waive_restocking']) && $override['override_waive_restocking'],
            ],
        ]);

        return new RefundBreakdown(
            refundSubtotal:   $newResult->refundSubtotal,
            refundGst:        $newResult->refundGst,
            refundDiscount:   $original->refundDiscount,
            refundRoundOff:   $original->refundRoundOff,
            refundLoyaltyPts: $newResult->refundLoyaltyPts,
            refundTotal:      $newResult->refundTotal,
            breakdown:        $extendedBreakdown,
        );
    }

    /**
     * Single authoritative path for recording a melt recovery.
     *
     * Takes the operator's actual measured weight and purity, credits the recovered
     * fine weight to the chosen lot, marks the item melted, and emits the MetalMovement
     * and AuditLog. Idempotent: aborts silently if the item is already melted.
     *
     * @param ReturnedItemDisposition $disposition
     * @param array $validated  Keys: actual_gross_weight, actual_purity, target_lot_id, notes (nullable)
     * @param int   $userId
     * @param int|null $invoiceId  For the MetalMovement invoice_id back-link (optional)
     */
    public function recordMeltRecovery(
        ReturnedItemDisposition $disposition,
        array $validated,
        int $userId,
        ?int $invoiceId = null,
    ): void {
        if ($disposition->disposition !== ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT) {
            throw new LogicException('Disposition is not sent_to_melt.');
        }

        DB::transaction(function () use ($disposition, $validated, $userId, $invoiceId) {
            $shopId = $disposition->shop_id;

            // Idempotency guard: if the item is already melted, do nothing.
            if ($disposition->item_id) {
                $item = Item::where('id', $disposition->item_id)
                    ->where('shop_id', $shopId)
                    ->lockForUpdate()
                    ->first();

                if (!$item || $item->status === 'melted') {
                    return;
                }
            } else {
                $item = null;
            }

            // Melt recovery is a gold-domain flow (returned gold melted back to a
            // vault lot). Fine weight via the single authority.
            $actualFineWeight = round(
                (float) $validated['actual_gross_weight'] * \App\Services\MetalRegistry::fineWeightMultiplier('gold', (float) $validated['actual_purity']),
                6
            );

            $lot = MetalLot::where('id', (int) $validated['target_lot_id'])
                ->where('shop_id', $shopId)
                ->lockForUpdate()
                ->firstOrFail();

            $lot->fine_weight_remaining = round((float) $lot->fine_weight_remaining + $actualFineWeight, 6);
            $lot->save();

            MetalMovement::record([
                'shop_id'        => $shopId,
                'from_lot_id'    => null,
                'to_lot_id'      => $lot->id,
                'fine_weight'    => $actualFineWeight,
                'type'           => 'return_melt_recovery',
                'reference_type' => 'return_line_item',
                'reference_id'   => $disposition->return_line_item_id,
                'user_id'        => $userId,
                'invoice_id'     => $invoiceId,
            ]);

            if ($item) {
                $item->update(['status' => 'melted']);
            }

            // Back-link the target lot on the disposition row (allowed by $allowedUpdateColumns).
            $disposition->update(['target_lot_id' => $lot->id]);

            AuditLog::create([
                'shop_id'     => $shopId,
                'user_id'     => $userId,
                'action'      => 'gold_recovery_recorded',
                'model_type'  => 'returned_item_disposition',
                'model_id'    => $disposition->id,
                'description' => "Gold recovered from returned item into lot #{$lot->lot_number}. Fine weight: {$actualFineWeight}g.",
                'data'        => [
                    'disposition_id'      => $disposition->id,
                    'return_line_item_id' => $disposition->return_line_item_id,
                    'target_lot_id'       => $lot->id,
                    'lot_number'          => $lot->lot_number,
                    'actual_gross_weight' => $validated['actual_gross_weight'],
                    'actual_purity'       => $validated['actual_purity'],
                    'actual_fine_weight'  => $actualFineWeight,
                    'notes'               => $validated['notes'] ?? null,
                ],
            ]);
        });
    }

    /**
     * Disposition router — drops a piece into one of the four destinations:
     *
     *   restocked       → item.status = in_stock (back on sale)
     *   sent_to_melt    → item.status = returned (parked; melt accounting deferred
     *                     to recordMeltRecovery() so operator can enter actual values)
     *   sent_to_rework  → item.status = returned (parked; job order links via target_job_order_id)
     *   written_off     → item.status = returned (off-stock, no accounting credit
     *                     beyond what the credit note already provided)
     *
     * Always emits a returned_item_dispositions row (append-only). The trigger
     * we added in M5 keeps items.return_disposition in sync.
     */
    private function applyDisposition(ReturnLineItem $returnLine, string $disposition, int $userId, ?string $notes = null): void
    {
        if (!$returnLine->item_id) {
            return;
        }

        $item = Item::where('id', $returnLine->item_id)
            ->where('shop_id', $returnLine->shop_id)
            ->lockForUpdate()
            ->first();
        if (!$item) {
            return;
        }

        $targetLotId = null;

        switch ($disposition) {
            case ReturnedItemDisposition::DISPOSITION_RESTOCKED:
                // If item is already pending_restock this is the operator confirming
                // inspection — always go to in_stock regardless of shop policy.
                if ($item->status === 'pending_restock') {
                    $item->update(['status' => 'in_stock']);
                } else {
                    $shopPrefs = \App\Models\ShopPreferences::where('shop_id', $item->shop_id)->first();
                    $item->update([
                        'status' => ($shopPrefs?->require_restock_inspection ?? false)
                            ? 'pending_restock'
                            : 'in_stock',
                    ]);
                }
                break;

            case ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT:
                // Park the item — the actual melt accounting (MetalMovement, lot credit,
                // status → 'melted') is deferred to recordMeltRecovery() so the operator
                // can enter the real measured weight and purity after physical melting.
                $item->update(['status' => 'returned']);
                break;

            case ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK:
                // Park the item — Phase 3 will route to a new job order. For
                // Phase 2 we just record the intent in the disposition table.
                $item->update(['status' => 'returned']);
                break;

            case ReturnedItemDisposition::DISPOSITION_WRITTEN_OFF:
                // Item is non-sellable. Off-stock, no lot credit (any value loss
                // is implicit in the credit note we just issued to the customer).
                $item->update(['status' => 'returned']);
                break;

            default:
                throw new LogicException("Unknown disposition '{$disposition}'.");
        }

        ReturnedItemDisposition::create([
            'shop_id'                  => $returnLine->shop_id,
            'return_line_item_id'      => $returnLine->id,
            'item_id'                  => $returnLine->item_id,
            'disposition'              => $disposition,
            'target_lot_id'            => $targetLotId,
            'target_job_order_id'      => null,
            'notes'                    => $notes,
            'dispositioned_by_user_id' => $userId,
            'dispositioned_at'         => now(),
        ]);
    }

    /**
     * Line-scoped compensating entries for partial returns. Same shape as the
     * full-invoice fireCompensatingEntries but scoped to the specific lines
     * coming back, with cash refund based on the credit-note total.
     */
    private function fireCompensatingEntriesForLines(Invoice $invoice, $creditNote, int $userId, $lines): void
    {
        // Cash refund — for the credit-note total (sum of refunds on selected lines).
        CashTransaction::record([
            'shop_id'        => $invoice->shop_id,
            'user_id'        => $userId,
            'type'           => 'out',
            'amount'         => abs((float) $creditNote->total),
            'source_type'    => 'credit_note',
            'source_id'      => $creditNote->id,
            'invoice_id'     => $invoice->id,
            'description'    => "Partial refund for {$invoice->invoice_number} via credit note {$creditNote->credit_note_number}",
            'reference_type' => 'credit_note',
            'reference_id'   => $creditNote->id,
        ]);

        // Customer-gold and metal-in mirrors only apply for full returns in
        // Phase 2. Partial returns of finished pieces don't currently distinguish
        // how much old-gold was applied to a particular line — the data lives at
        // invoice level, not line level. Document as a Phase 3 enhancement
        // (proportional old-gold reversal). Phase 2 leaves these untouched for
        // partial returns; legitimate use cases (returning all of a piece that
        // had old-gold trade-in) should use the full-return path.
    }

    private function writeAuditLog(Invoice $invoice, ReturnOrder $returnOrder, $creditNote, $beforeInvoice, string $reason): void
    {
        try {
            AuditLog::create([
                'shop_id'     => $invoice->shop_id,
                'action'      => 'return_order_settled',
                'model_type'  => 'return_order',
                'model_id'    => $returnOrder->id,
                'description' => "Full return of invoice {$invoice->invoice_number} settled by credit note {$creditNote->credit_note_number}.",
                'data'        => [
                    'invoice_id'       => $invoice->id,
                    'invoice_number'   => $invoice->invoice_number,
                    'return_order_id'  => $returnOrder->id,
                    'credit_note_id'   => $creditNote->id,
                    'credit_note_number' => $creditNote->credit_note_number,
                    'reason'           => $reason,
                    'refund_total'     => $creditNote->total,
                ],
                'before'      => $beforeInvoice,
                'after'       => DB::table('invoices')->where('id', $invoice->id)->first(),
                'target'      => ['type' => 'invoice', 'id' => $invoice->id],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Return audit log failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
        }
    }
}
