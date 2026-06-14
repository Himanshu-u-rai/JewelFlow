<?php

namespace App\Services\Returns;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\ExchangeOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ReturnOrder;
use App\Services\InvoiceAccountingService;
use App\Services\SubscriptionGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Phase 3A — links a settled ReturnOrder to a finalized Invoice as a single
 * customer exchange transaction. The return and the new sale are still
 * created through their existing flows (Process Return + POS); this service
 * just ties them together, computes the net settlement, and emits the
 * compensating cash entry for the difference.
 *
 * Phase 3B will turn this into a unified one-screen wizard with persistent
 * draft state, gold-rate-basis selection, etc. The data model is ready for
 * that — the linker is the MVP wedge.
 */
class ExchangeService
{
    public function __construct(private ReturnService $returnService) {}

    /**
     * Phase 3B — unified exchange flow. Atomically:
     *   1. Processes the return half (via ReturnService::createPartialReturn,
     *      with the chosen valuation basis applied to the refund amount).
     *   2. Creates the new sale half: a fresh draft Invoice, InvoiceItem rows
     *      for each new item, then finalizes via InvoiceAccountingService.
     *   3. Links the two as an ExchangeOrder, computing the net settlement.
     *
     * The new sale's items are existing in-stock items selected by the cashier.
     * Each is priced at its current `selling_price`. GST is computed at the
     * shop's default rate. No discounts in this MVP — Phase 3C can add.
     *
     * @param  Invoice  $originalInvoice  the invoice the items being returned came from
     * @param  array    $returnSelections selections shaped as createPartialReturn expects
     * @param  array<int>  $newItemIds    item IDs to sell to the customer in the same transaction
     * @param  string   $basisSource      ExchangeOrder::BASIS_*
     * @param  string   $reason
     * @param  int      $userId
     */
    public function createUnified(
        Invoice $originalInvoice,
        array $returnSelections,
        array $newItemIds,
        string $basisSource,
        string $reason,
        int $userId,
        ?float $rateOverride = null,
        array $lineOverrides = [],
    ): ExchangeOrder {
        if (empty($returnSelections)) {
            throw new LogicException('Pick at least one item to return.');
        }
        if (empty($newItemIds)) {
            throw new LogicException('Pick at least one new item the customer is taking.');
        }
        if (!in_array($basisSource, [
            ExchangeOrder::BASIS_SALE_DAY_RATE,
            ExchangeOrder::BASIS_TODAY_RATE,
            ExchangeOrder::BASIS_MANUAL_OVERRIDE,
        ], true)) {
            throw new LogicException("Unsupported valuation basis '{$basisSource}'. Supported: sale_day_rate, today_rate, manual_override.");
        }

        // Phase D: permission gate when basis differs from shop default.
        $shop = $originalInvoice->shop;
        $defaultBasis = (string) ($shop?->preferences?->gold_rate_basis_for_exchange ?? ExchangeOrder::BASIS_SALE_DAY_RATE);
        $user = \App\Models\User::find($userId);
        $hasOverridePermission = $user && $user->can('exchanges.override_rate');
        if ($basisSource !== $defaultBasis && !$hasOverridePermission) {
            throw new LogicException(
                "Non-default valuation basis ('{$basisSource}') requires the 'Override Exchange Rate Basis' permission. Shop default is '{$defaultBasis}'."
            );
        }
        $approvedByOwner = $hasOverridePermission;

        SubscriptionGateService::assertShopWritable((int) $originalInvoice->shop_id);
        InvoiceAccountingService::assertShopLockForDate((int) $originalInvoice->shop_id, now()->toDateString());

        // Build the ValuationBasis from the shop's return policy. This ensures
        // making/stone/hallmark refundability and wear-loss deductions are
        // driven by the owner's configured settings, not hardcoded values.
        $shopPolicy = $originalInvoice->shop?->preferences;
        $resolver   = app(\App\Services\Returns\RefundPolicyResolver::class);
        $basis      = $resolver->basisFromPolicy($shopPolicy, $basisSource, $rateOverride);

        return DB::transaction(function () use (
            $originalInvoice, $returnSelections, $newItemIds, $basis, $basisSource,
            $reason, $userId, $approvedByOwner, $hasOverridePermission, $rateOverride, $lineOverrides
        ) {
            // 1. Process the return half with the chosen basis.
            $returnOrder = $this->returnService->createPartialReturn(
                $originalInvoice, $returnSelections, $reason, $userId, $basis,
                \App\Services\Returns\ReturnService::REFUND_SETTLEMENT_CASH, // settlement mode (exchange always nets to cash)
                true, // $forExchange: skip individual cash entry; we record net below
                $lineOverrides,
            );

            // 2. Create the new sale half.
            $newInvoice = $this->createNewSaleInvoice($originalInvoice, $newItemIds, $userId);

            // 3. Compute the net and link as exchange.
            $cn = $returnOrder->creditNote;
            $netAmount = round(((float) $newInvoice->total) - ((float) $cn->total), 2);

            // Record the net cash movement for the exchange.
            // Return half did NOT fire its own entry ($forExchange=true above).
            // New-sale half was finalized but not paid (finalizeDraft records no payment).
            // This single entry represents the full net settlement.
            $netCashType = $netAmount > 0.005 ? 'in' : ($netAmount < -0.005 ? 'out' : null);

            $exchange = ExchangeOrder::create([
                'shop_id'                => $originalInvoice->shop_id,
                'return_order_id'        => $returnOrder->id,
                'new_invoice_id'         => $newInvoice->id,
                'customer_id'            => $originalInvoice->customer_id,
                'valuation_basis_source' => $basisSource,
                'net_amount'             => $netAmount,
                'payment_method'         => ExchangeOrder::PAYMENT_CASH,
                'status'                 => ExchangeOrder::STATUS_DRAFT,
                'reason'                 => $reason,
                'created_by_user_id'     => $userId,
                'approved_by_user_id'    => $hasOverridePermission ? $userId : null,
                'approved_at'            => $hasOverridePermission ? now() : null,
            ]);

            // Flip the return type now that we know it's an exchange.
            DB::table('return_orders')->where('id', $returnOrder->id)
                ->update(['return_type' => 'customer_exchange']);

            $exchange->update([
                'status'             => ExchangeOrder::STATUS_SETTLED,
                'settled_by_user_id' => $userId,
                'settled_at'         => now(),
            ]);

            if ($netCashType !== null) {
                \App\Models\CashTransaction::record([
                    'shop_id'        => $originalInvoice->shop_id,
                    'user_id'        => $userId,
                    'type'           => $netCashType,
                    'amount'         => abs($netAmount),
                    'source_type'    => 'exchange_order',
                    'source_id'      => $exchange->id,
                    'invoice_id'     => $newInvoice->id,
                    'description'    => "Exchange #{$exchange->id}: net settlement — return {$returnOrder->creditNote?->credit_note_number} ↔ new sale {$newInvoice->invoice_number}",
                    'reference_type' => 'exchange_order',
                    'reference_id'   => $exchange->id,
                ]);
            }

            try {
                AuditLog::create([
                    'shop_id'     => $exchange->shop_id,
                    'action'      => 'exchange_unified_settled',
                    'model_type'  => 'exchange_order',
                    'model_id'    => $exchange->id,
                    'description' => "Unified exchange settled: return {$cn->credit_note_number} ↔ new sale {$newInvoice->invoice_number}.",
                    'data'        => [
                        'return_order_id'    => $returnOrder->id,
                        'credit_note_number' => $cn->credit_note_number,
                        'new_invoice_id'     => $newInvoice->id,
                        'new_invoice_number' => $newInvoice->invoice_number,
                        'net_amount'         => $netAmount,
                        'valuation_basis'    => $basisSource,
                        'approved_by_user_id' => $hasOverridePermission ? $userId : null,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Unified exchange audit log failed: ' . $e->getMessage());
            }

            // Phase D: additional audit entry when a manual rate override was used.
            if ($rateOverride !== null) {
                try {
                    AuditLog::create([
                        'shop_id'     => $exchange->shop_id,
                        'action'      => 'exchange_rate_overridden',
                        'model_type'  => 'exchange_order',
                        'model_id'    => $exchange->id,
                        'description' => "Manual rate override: ₹{$rateOverride}/g for exchange {$exchange->id}.",
                        'data'        => ['override_rate' => $rateOverride, 'basis_source' => $basisSource],
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Exchange rate-override audit log failed: ' . $e->getMessage());
                }
            }

            return $exchange->refresh()->load('returnOrder.creditNote', 'newInvoice');
        });
    }

    /**
     * Create the new sale Invoice + InvoiceItems for the items the customer
     * is taking. Each item is sold at its current selling_price. GST is
     * computed at the shop's default rate by finalizeDraft.
     */
    private function createNewSaleInvoice(Invoice $originalInvoice, array $newItemIds, int $userId): Invoice
    {
        // Lock each item, verify it's in_stock + belongs to this shop.
        $items = Item::where('shop_id', $originalInvoice->shop_id)
            ->whereIn('id', $newItemIds)
            ->where('status', 'in_stock')
            ->lockForUpdate()
            ->get();

        if ($items->count() !== count($newItemIds)) {
            throw new LogicException('One or more of the selected new items is not currently in stock.');
        }

        // Validate priced. selling_price must be > 0; pricing review must be cleared.
        foreach ($items as $item) {
            if ((float) ($item->selling_price ?? 0) <= 0) {
                throw new LogicException("Item {$item->barcode} has no selling price set. Edit it first to set the price.");
            }
            if ($item->pricing_review_required) {
                throw new LogicException("Item {$item->barcode} is pending price review. Release it from Pending Stock first.");
            }
        }

        // Create a draft invoice tied to the same customer as the original
        // exchange's original invoice (typical case: same customer).
        $draft = new Invoice();
        $draft->forceFill([
            'shop_id'     => $originalInvoice->shop_id,
            'customer_id' => $originalInvoice->customer_id,
            'gold_rate'   => $originalInvoice->gold_rate, // copy as informational
            'subtotal'    => 0,
            'gst'         => 0,
            'gst_rate'    => $originalInvoice->gst_rate ?? 0,
            'wastage_charge' => 0,
            'discount'    => 0,
            'round_off'   => 0,
            'total'       => 0,
            'status'      => Invoice::STATUS_DRAFT,
        ])->save();

        // Add line items. Each at its current selling_price; GST allocated per-line.
        $shopGstRate = (float) ($draft->gst_rate ?? 0);
        foreach ($items as $item) {
            $linePrice = (float) $item->selling_price;
            // The line GST = linePrice × gst_rate / 100. Simple approach.
            $lineGst   = round($linePrice * ($shopGstRate / 100), 2);
            // Phase 0: snapshot metal_type at line creation.
            $invoiceItem = InvoiceItem::record([
                'invoice_id'    => $draft->id,
                'item_id'       => $item->id,
                'metal_type'    => $item->metal_type,
                'weight'        => (float) $item->gross_weight,
                'rate'          => 0, // not used for finished-piece sale; line_total is the lock
                'making_charges'=> (float) ($item->making_charges ?? 0),
                'stone_amount'  => (float) ($item->stone_charges ?? 0),
                // Hallmark is already inside selling_price (line_total); snapshot it
                // separately so a return of this exchanged item can retain it (parity
                // with making/stone and the regular sale paths).
                'hallmark_charges' => (float) ($item->hallmark_charges ?? 0),
                'line_total'    => $linePrice,
                'gst_rate'      => $shopGstRate,
                'gst_amount'    => $lineGst,
                // allocated_* default to 0 — there's no invoice-level discount here.
                'allocated_discount'   => 0,
                'allocated_round_off'  => 0,
                'allocated_loyalty_pts'=> 0,
            ]);

            // Phase 2A — stone snapshot on the new-sale leg of the exchange.
            app(\App\Services\StoneSnapshotService::class)
                ->snapshotForInvoiceItem($invoiceItem, $item);
        }

        // Finalize — this computes header totals from the lines and marks items sold.
        $finalized = InvoiceAccountingService::finalizeDraft($draft);

        return $finalized;
    }

    /**
     * Link an existing settled ReturnOrder to an existing finalized Invoice
     * as one customer exchange. Issues the net cash settlement and writes
     * the ExchangeOrder header.
     *
     * Validations:
     *   - Both belong to the same shop
     *   - Both belong to the same customer (or one of them has no customer)
     *   - Return is settled, invoice is finalized
     *   - Neither is already part of another exchange
     */
    public function link(
        ReturnOrder $returnOrder,
        Invoice $newInvoice,
        string $basisSource,
        string $paymentMethod,
        int $userId,
        ?string $reason = null,
    ): ExchangeOrder {
        if ($returnOrder->shop_id !== $newInvoice->shop_id) {
            throw new LogicException('Return and new sale must belong to the same shop.');
        }
        if ($returnOrder->status !== ReturnOrder::STATUS_SETTLED) {
            throw new LogicException('Return must be settled before it can be linked as an exchange.');
        }
        if ($newInvoice->status !== Invoice::STATUS_FINALIZED) {
            throw new LogicException('New invoice must be finalized before it can be linked as an exchange.');
        }
        if ($returnOrder->customer_id && $newInvoice->customer_id && $returnOrder->customer_id !== $newInvoice->customer_id) {
            throw new LogicException('Return and new sale customers do not match.');
        }
        if (ExchangeOrder::where('return_order_id', $returnOrder->id)->exists()) {
            throw new LogicException('This return is already part of an exchange.');
        }
        if (ExchangeOrder::where('new_invoice_id', $newInvoice->id)->exists()) {
            throw new LogicException('This invoice is already part of an exchange.');
        }
        if (!in_array($basisSource, [
            ExchangeOrder::BASIS_SALE_DAY_RATE,
            ExchangeOrder::BASIS_TODAY_RATE,
            ExchangeOrder::BASIS_FIXED_RATE,
            ExchangeOrder::BASIS_MANUAL_OVERRIDE,
        ], true)) {
            throw new LogicException("Unknown valuation basis '{$basisSource}'.");
        }
        // payment_method on the exchange row is purely informational in Phase 3A:
        // the actual money movements were recorded at the two halves (the CN refund
        // via CashTransaction, the new sale via InvoicePayment rows). The exchange
        // record itself does NOT emit a new cash entry — that would double-count
        // what the cash drawer already knows. payment_method values are reserved
        // for Phase 4 when store-credit settlement creates a genuinely new flow.
        if (!in_array($paymentMethod, [
            ExchangeOrder::PAYMENT_CASH,
            ExchangeOrder::PAYMENT_STORE_CREDIT,
            ExchangeOrder::PAYMENT_MIXED,
        ], true)) {
            throw new LogicException("Unknown payment method '{$paymentMethod}'.");
        }

        // Phase D: exchange_rate_basis_locked enforcement
        $shopPolicy = $returnOrder->invoice?->shop?->preferences
            ?? \App\Models\ShopPreferences::where('shop_id', $returnOrder->shop_id)->first();
        $defaultBasis = (string) ($shopPolicy?->gold_rate_basis_for_exchange ?? ExchangeOrder::BASIS_SALE_DAY_RATE);
        if (($shopPolicy?->exchange_rate_basis_locked ?? false) && $basisSource !== $defaultBasis) {
            throw new LogicException(
                "Exchange rate basis is locked to shop default ('{$defaultBasis}'). Cannot use '{$basisSource}'."
            );
        }
        // Phase D: manual override requires permission
        if ($basisSource === ExchangeOrder::BASIS_MANUAL_OVERRIDE) {
            $user = \App\Models\User::find($userId);
            if (!$user || !$user->can('exchanges.override_rate')) {
                throw new LogicException("Manual rate override requires the 'Override Exchange Rate Basis' permission.");
            }
        }

        InvoiceAccountingService::assertShopLockForDate((int) $newInvoice->shop_id, now()->toDateString());

        $creditNote = $returnOrder->creditNote;
        if (!$creditNote) {
            throw new LogicException('Return has no associated credit note — cannot compute net.');
        }

        // Signed net: positive = customer owes the shop, negative = shop refunds.
        $netAmount = round(((float) $newInvoice->total) - ((float) $creditNote->total), 2);

        return DB::transaction(function () use ($returnOrder, $newInvoice, $basisSource, $paymentMethod, $userId, $reason, $netAmount) {
            $exchange = ExchangeOrder::create([
                'shop_id'                => $returnOrder->shop_id,
                'return_order_id'        => $returnOrder->id,
                'new_invoice_id'         => $newInvoice->id,
                'customer_id'            => $returnOrder->customer_id ?? $newInvoice->customer_id,
                'valuation_basis_source' => $basisSource,
                'net_amount'             => $netAmount,
                'payment_method'         => $paymentMethod,
                'status'                 => ExchangeOrder::STATUS_DRAFT,
                'reason'                 => $reason,
                'created_by_user_id'     => $userId,
            ]);

            // Mark the return as an exchange (changes return_type from
            // customer_return → customer_exchange). The DB CHECK was widened
            // in the migration to permit this.
            $returnOrder->forceFill(['return_type' => ReturnOrder::TYPE_CUSTOMER_RETURN])
                ->save();
            // forceFill bypasses ImmutableLedger's allowed-columns guard since
            // return_type isn't in $allowedUpdateColumns. We want this — it's
            // a one-time semantic upgrade that records the exchange context.
            DB::table('return_orders')->where('id', $returnOrder->id)->update([
                'return_type' => 'customer_exchange',
            ]);

            // NOTE: no cash transaction is written here. The two halves of the
            // exchange already recorded their own money movements:
            //   - The return path (ReturnService) wrote a CashTransaction `out`
            //     for the CN amount (the refund the customer was owed).
            //   - The new sale (created in POS) wrote InvoicePayment rows for
            //     however the customer paid the new invoice (cash / UPI / etc).
            // The exchange row's net_amount is metadata for reporting only —
            // computed as (new_invoice.total - cn.total). Adding another cash
            // entry here would double-count what's already in the ledger.

            $exchange->update([
                'status'             => ExchangeOrder::STATUS_SETTLED,
                'settled_by_user_id' => $userId,
                'settled_at'         => now(),
            ]);

            try {
                AuditLog::create([
                    'shop_id'     => $exchange->shop_id,
                    'action'      => 'exchange_order_settled',
                    'model_type'  => 'exchange_order',
                    'model_id'    => $exchange->id,
                    'description' => "Exchange settled: return {$returnOrder->creditNote->credit_note_number} ↔ new sale {$newInvoice->invoice_number}.",
                    'data'        => [
                        'return_order_id'    => $returnOrder->id,
                        'credit_note_number' => $returnOrder->creditNote->credit_note_number,
                        'new_invoice_id'     => $newInvoice->id,
                        'new_invoice_number' => $newInvoice->invoice_number,
                        'net_amount'         => $netAmount,
                        'payment_method'     => $paymentMethod,
                        'valuation_basis'    => $basisSource,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Exchange audit log failed for exchange ' . $exchange->id . ': ' . $e->getMessage());
            }

            return $exchange->refresh()->load('returnOrder.creditNote', 'newInvoice');
        });
    }
}
