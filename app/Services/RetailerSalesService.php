<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Item;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\InvoiceOfferApplication;
use App\Models\CashTransaction;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\CustomerGoldTransaction;
use App\Models\SchemeEnrollment;
use App\Services\InvoiceAccountingService;
use App\Services\OldMetalWeeklyLotService;
use App\Services\SubscriptionGateService;
use App\Services\PricingEngine;
use App\Services\PricingEngine\QuoteInput;
use App\Services\SchemeService;
use Illuminate\Validation\ValidationException;

class RetailerSalesService
{
    /**
     * Sell one or more items from a retailer shop.
     *
     * Uses each item's selling_price as the base (no gold rate / wastage).
     * Supports discount, round_off, and split payments.
     */
    public static function sellItems(
        int $customerId,
        array $itemIds,
        float $discount = 0,
        float $roundOff = 0,
        array $payments = [],
        ?int $offerSchemeId = null,
        ?array $schemeRedemption = null,
        bool $allowAutoOfferFallback = true,
    ) {
        return DB::transaction(function () use (
            $customerId, $itemIds, $discount, $roundOff, $payments, $offerSchemeId, $schemeRedemption, $allowAutoOfferFallback
        ) {
            $shop = auth()->user()->shop;
            if (!$shop || !$shop->isRetailer()) {
                throw new \LogicException('Retailer edition required for this sale flow.');
            }
            $shopId = $shop->id;
            SubscriptionGateService::assertShopWritable($shopId);

            // Lock all items
            $items = Item::where('shop_id', $shopId)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($itemIds)) {
                throw ValidationException::withMessages([
                    'item_ids' => 'One or more items are not available for sale.',
                ]);
            }

            foreach ($items as $item) {
                if ($item->status !== 'in_stock') {
                    throw ValidationException::withMessages([
                        'item_ids' => 'One or more items are not available for sale.',
                    ]);
                }
            }

            // Sum selling prices (kept for audit only — engine recomputes internally)
            $sellingPrice = (float) $items->sum('selling_price');

            // Route ALL pricing math through the engine. Same QuoteInput → same
            // PricingBreakdown, bit-identical to the inline math this replaces.
            /** @var PricingEngine $engine */
            $engine = app(PricingEngine::class);
            $quoteInput = QuoteInput::retailer(
                shopId: $shopId,
                customerId: $customerId,
                itemIds: $items->pluck('id')->toArray(),
                manualDiscount: (float) $discount,
                offerSchemeId: $offerSchemeId,
                allowAutoOfferFallback: $allowAutoOfferFallback,
            );
            $breakdown = $engine->compute($quoteInput);

            $manualDiscount = $breakdown->manualDiscount;
            $offerDiscount  = $breakdown->offerDiscount;
            $totalDiscount  = $breakdown->totalDiscount;
            $gstRate        = $breakdown->gstRate;
            $offerPayload   = $breakdown->offer;

            // Preserve today's round_off behaviour: only override the function
            // param when the shop has opted-in to auto-rounding. Shops with
            // rounding_method='none' (or no preference row) keep using the
            // caller-supplied $roundOff exactly as before.
            $effectiveRoundOff = $breakdown->roundingMethod === 'none'
                ? round((float) $roundOff, 2)
                : $breakdown->roundingAdjustment;

            // Create invoice
            $invoice = InvoiceAccountingService::createDraft([
                'customer_id'    => $customerId,
                'shop_id'        => $shopId,
                'gold_rate'      => 0,
                'gst_rate'       => $gstRate,
                'wastage_charge' => 0,
                'discount'       => $totalDiscount,
                'round_off'      => $effectiveRoundOff,
            ]);

            // Engine has already apportioned per-line GST via the same
            // largest-remainder helper, so SUM(line.gst_amount) == invoice.gst
            // is preserved by construction.
            $apportioned = [];
            foreach ($breakdown->lines as $line) {
                $apportioned[(int) $line['item_id']] = (float) ($line['gst_amount'] ?? 0);
            }

            // Invoice lines — one per item
            foreach ($items as $item) {
                InvoiceItem::record([
                    'invoice_id'     => $invoice->id,
                    'item_id'        => $item->id,
                    'weight'         => $item->net_metal_weight,
                    'rate'           => $item->selling_price,
                    'making_charges' => $item->making_charges ?? 0,
                    'stone_amount'   => $item->stone_charges ?? 0,
                    'line_total'     => $item->selling_price,
                    'gst_rate'       => $gstRate,
                    'gst_amount'     => $apportioned[$item->id] ?? 0,
                ]);
            }

            $invoice = InvoiceAccountingService::finalizeDraft($invoice, $gstRate);

            if ($offerPayload) {
                InvoiceOfferApplication::record([
                    'shop_id' => $shopId,
                    'invoice_id' => $invoice->id,
                    'scheme_id' => $offerPayload['scheme_id'],
                    'customer_id' => $customerId,
                    'scheme_name_snapshot' => $offerPayload['scheme_name'],
                    'discount_type' => $offerPayload['discount_type'],
                    'discount_value' => $offerPayload['discount_value'],
                    'discount_amount' => $offerPayload['discount_amount'],
                    'auto_applied' => (bool) ($offerPayload['auto_applied'] ?? false),
                    'rule_snapshot' => $offerPayload['rule_snapshot'] ?? null,
                    'applied_at' => now(),
                ]);

                \App\Models\Scheme::where('id', $offerPayload['scheme_id'])
                    ->where('shop_id', $shopId)
                    ->lockForUpdate()
                    ->increment('usage_count');
            }

            // ── Record split payments ───────────────────────────────
            if (empty($payments)) {
                $payments = [['mode' => 'cash', 'amount' => (float) $invoice->total]];
            }

            $paymentTotal = 0;

            if (!empty($schemeRedemption['enrollment_id']) && !empty($schemeRedemption['amount'])) {
                $enrollment = SchemeEnrollment::query()
                    ->where('shop_id', $shopId)
                    ->where('customer_id', $customerId)
                    ->findOrFail((int) $schemeRedemption['enrollment_id']);

                $redemption = app(SchemeService::class)->applyRedemptionToInvoice(
                    $enrollment,
                    $invoice,
                    (float) $schemeRedemption['amount'],
                    $schemeRedemption['note'] ?? 'POS scheme redemption'
                );

                $paymentTotal += (float) $redemption->amount;
            }

            foreach ($payments as $p) {
                $mode   = $p['mode'];
                // Reset per-iteration to prevent stale lot bleeding into subsequent payments
                $weeklyLot = null;
                $weeklyLotService = null;

                if ($mode === 'emi') {
                    throw new \Exception('EMI checkout must be completed from the EMI form.');
                }

                $amount = round((float) ($p['amount'] ?? 0), 2);
                if ($amount <= 0) continue;

                $paymentTotal += $amount;

                $attrs = [
                    'invoice_id'        => $invoice->id,
                    'shop_id'           => $shopId,
                    'mode'              => $mode,
                    'amount'            => $amount,
                    'reference'         => $p['reference'] ?? null,
                    'note'              => $p['note'] ?? null,
                    'payment_method_id' => $p['payment_method_id'] ?? null,
                ];

                // Old-gold / old-silver payments (accepted even by retailers)
                if (in_array($mode, ['old_gold', 'old_silver'])) {
                    $grossWt   = (float) ($p['metal_gross_weight'] ?? 0);
                    $purity    = (float) ($p['metal_purity'] ?? 0);
                    $testLoss  = (float) ($p['metal_test_loss'] ?? 0);
                    $netWt     = $grossWt * (1 - ($testLoss / 100));
                    // Fine weight via the single authority (gold → /24, silver → /1000).
                    $fineWt    = $netWt * \App\Services\MetalRegistry::fineWeightMultiplier(
                        $mode === 'old_gold' ? 'gold' : 'silver',
                        $purity
                    );
                    $ratePerG  = (float) ($p['metal_rate_per_gram'] ?? 0);

                    $attrs['metal_type']          = $mode === 'old_gold' ? 'gold' : 'silver';
                    $attrs['metal_gross_weight']  = $grossWt;
                    $attrs['metal_purity']        = $purity;
                    $attrs['metal_test_loss']     = $testLoss;
                    $attrs['metal_fine_weight']   = round($fineWt, 3);
                    $attrs['metal_rate_per_gram'] = $ratePerG;

                    // Find or create the weekly lot and link this payment to it
                    if ($attrs['metal_fine_weight'] > 0) {
                        $weeklyLotService = app(OldMetalWeeklyLotService::class);
                        $weeklyLot = $weeklyLotService->findOrCreateForDate(
                            $shopId,
                            $attrs['metal_type'],
                            now()
                        );
                        $attrs['weekly_lot_id'] = $weeklyLot->id;
                    }
                }

                InvoicePayment::record($attrs);

                // Create CashTransaction per non-metal payment mode
                if (in_array($mode, ['cash', 'upi', 'bank', 'wallet', 'other'])) {
                    CashTransaction::record([
                        'shop_id'      => $shopId,
                        'user_id'      => auth()->id(),
                        'type'         => 'in',
                        'amount'       => $amount,
                        'source_type'  => 'invoice',
                        'source_id'    => $invoice->id,
                        'invoice_id'   => $invoice->id,
                        'payment_mode' => $mode,
                        'description'  => "Sale ({$mode}) - Invoice {$invoice->invoice_number}",
                    ]);
                }

                // Accumulate onto the weekly lot + record movement for old-gold/old-silver
                if (in_array($mode, ['old_gold', 'old_silver'])) {
                    $fineWeight = (float) ($attrs['metal_fine_weight'] ?? 0);

                    if ($fineWeight > 0 && isset($weeklyLot)) {
                        $weeklyLotService->addFineWeight($weeklyLot, $fineWeight, $amount);

                        MetalMovement::record([
                            'shop_id'        => $shopId,
                            'from_lot_id'    => null,
                            'to_lot_id'      => $weeklyLot->id,
                            'fine_weight'    => $fineWeight,
                            'type'           => 'old_metal_in',
                            'reference_type' => 'invoice',
                            'reference_id'   => $invoice->id,
                            'invoice_id'     => $invoice->id,
                            'user_id'        => auth()->id(),
                        ]);

                        // Record CustomerGoldTransaction for the metal received
                        if ($invoice->customer_id) {
                            CustomerGoldTransaction::record([
                                'shop_id'        => $shopId,
                                'customer_id'    => $invoice->customer_id,
                                'gross_weight'   => $attrs['metal_gross_weight'],
                                'purity'         => $attrs['metal_purity'],
                                'fine_gold'      => -1 * $fineWeight,
                                'type'           => 'old_metal_in',
                                'reference_type' => 'invoice',
                                'reference_id'   => $invoice->id,
                                'invoice_id'     => $invoice->id,
                            ]);
                        }

                    }
                }
            }

            // Validate payment total covers invoice total (0.01 tolerance for floating point noise)
            if (abs($paymentTotal - (float) $invoice->total) > 0.01) {
                throw new \Exception("Payment total (₹{$paymentTotal}) does not match invoice total (₹{$invoice->total})");
            }

            // Mark all items sold
            foreach ($items as $item) {
                $item->status = 'sold';
                $item->save();
            }

            // Audit
            \App\Models\AuditLog::create([
                'shop_id'    => $shopId,
                'user_id'    => auth()->id(),
                'action'     => 'sale',
                'model_type' => 'invoice',
                'model_id'   => $invoice->id,
                'data'       => [
                    'customer_id'   => $customerId,
                    'item_ids'      => $items->pluck('id')->toArray(),
                    'selling_price' => $sellingPrice,
                    'total'         => (float) $invoice->total,
                    'discount'      => $totalDiscount,
                    'manual_discount' => $manualDiscount,
                    'offer_discount' => $offerDiscount,
                    'offer_scheme_id' => $offerPayload['scheme_id'] ?? null,
                    'round_off'     => $effectiveRoundOff,
                    'payments'      => collect($payments)->map(fn($p) => [
                        'mode' => $p['mode'], 'amount' => $p['amount'] ?? 0,
                    ])->toArray(),
                    'scheme_redemption' => !empty($schemeRedemption['enrollment_id']) ? [
                        'enrollment_id' => (int) $schemeRedemption['enrollment_id'],
                        'amount' => round((float) ($schemeRedemption['amount'] ?? 0), 2),
                    ] : null,
                ],
            ]);

            return $invoice;
        });
    }

    /**
     * Prepare EMI checkout draft from POS.
     * Final invoice and payments are created only after EMI form submission.
     */
    public static function prepareEmiDraftSale(
        int $customerId,
        array $itemIds,
        float $discount = 0,
        float $roundOff = 0,
        ?int $offerSchemeId = null,
        bool $allowAutoOfferFallback = true,
    ) {
        return DB::transaction(function () use ($customerId, $itemIds, $discount, $roundOff, $offerSchemeId, $allowAutoOfferFallback) {
            $shop = auth()->user()->shop;
            if (!$shop || !$shop->isRetailer()) {
                throw new \LogicException('Retailer edition required for this sale flow.');
            }

            $shopId = $shop->id;
            SubscriptionGateService::assertShopWritable($shopId);

            $items = Item::where('shop_id', $shopId)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($itemIds)) {
                throw ValidationException::withMessages([
                    'item_ids' => 'One or more items are not available for sale.',
                ]);
            }

            foreach ($items as $item) {
                if ($item->status !== 'in_stock') {
                    throw ValidationException::withMessages([
                        'item_ids' => 'One or more items are not available for sale.',
                    ]);
                }
            }

            // Route ALL pricing math through the engine — identical pipeline to
            // sellItems() so the EMI draft and the final invoice agree.
            /** @var PricingEngine $engine */
            $engine = app(PricingEngine::class);
            $quoteInput = QuoteInput::retailer(
                shopId: $shopId,
                customerId: $customerId,
                itemIds: $items->pluck('id')->toArray(),
                manualDiscount: (float) $discount,
                offerSchemeId: $offerSchemeId,
                allowAutoOfferFallback: $allowAutoOfferFallback,
            );
            $breakdown = $engine->compute($quoteInput);

            $manualDiscount = $breakdown->manualDiscount;
            $offerDiscount  = $breakdown->offerDiscount;
            $totalDiscount  = $breakdown->totalDiscount;
            $gstRate        = $breakdown->gstRate;
            $offerPayload   = $breakdown->offer;

            // Preserve today's round_off behaviour: respect the caller's value
            // unless the shop has opted-in to auto-rounding via preferences.
            $effectiveRoundOff = $breakdown->roundingMethod === 'none'
                ? round((float) $roundOff, 2)
                : $breakdown->roundingAdjustment;

            $invoice = InvoiceAccountingService::createDraft([
                'customer_id'    => $customerId,
                'shop_id'        => $shopId,
                'gold_rate'      => 0,
                'gst_rate'       => $gstRate,
                'wastage_charge' => 0,
                'discount'       => $totalDiscount,
                'round_off'      => $effectiveRoundOff,
            ]);

            // Engine has already apportioned per-line GST via the same
            // largest-remainder helper, so SUM(line.gst_amount) == invoice.gst
            // when this draft is finalized.
            $apportionedEmi = [];
            foreach ($breakdown->lines as $line) {
                $apportionedEmi[(int) $line['item_id']] = (float) ($line['gst_amount'] ?? 0);
            }

            foreach ($items as $item) {
                InvoiceItem::record([
                    'invoice_id'     => $invoice->id,
                    'item_id'        => $item->id,
                    'weight'         => $item->net_metal_weight,
                    'rate'           => $item->selling_price,
                    'making_charges' => $item->making_charges ?? 0,
                    'stone_amount'   => $item->stone_charges ?? 0,
                    'line_total'     => $item->selling_price,
                    'gst_rate'       => $gstRate,
                    'gst_amount'     => $apportionedEmi[$item->id] ?? 0,
                ]);
            }

            if ($offerPayload) {
                InvoiceOfferApplication::record([
                    'shop_id' => $shopId,
                    'invoice_id' => $invoice->id,
                    'scheme_id' => $offerPayload['scheme_id'],
                    'customer_id' => $customerId,
                    'scheme_name_snapshot' => $offerPayload['scheme_name'],
                    'discount_type' => $offerPayload['discount_type'],
                    'discount_value' => $offerPayload['discount_value'],
                    'discount_amount' => $offerPayload['discount_amount'],
                    'auto_applied' => (bool) ($offerPayload['auto_applied'] ?? false),
                    'rule_snapshot' => $offerPayload['rule_snapshot'] ?? null,
                    'applied_at' => now(),
                ]);

                \App\Models\Scheme::where('id', $offerPayload['scheme_id'])
                    ->where('shop_id', $shopId)
                    ->lockForUpdate()
                    ->increment('usage_count');
            }

            // Reserve items so they can't be sold while user fills EMI form
            foreach ($items as $item) {
                $item->status = 'reserved';
                $item->save();
            }

            \App\Models\AuditLog::create([
                'shop_id'    => $shopId,
                'user_id'    => auth()->id(),
                'action'     => 'emi_sale_draft_created',
                'model_type' => 'invoice',
                'model_id'   => $invoice->id,
                'data'       => [
                    'customer_id' => $customerId,
                    'item_ids'    => $items->pluck('id')->toArray(),
                    'discount'    => $totalDiscount,
                    'manual_discount' => $manualDiscount,
                    'offer_discount' => $offerDiscount,
                    'offer_scheme_id' => $offerPayload['scheme_id'] ?? null,
                    'round_off'   => $effectiveRoundOff,
                ],
            ]);

            return $invoice;
        });
    }
}
