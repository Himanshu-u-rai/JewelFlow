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
use App\Services\SubscriptionGateService;
use App\Services\OfferEngineService;
use App\Services\SchemeService;

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
                throw new \Exception("One or more items not found");
            }

            foreach ($items as $item) {
                if ($item->status !== 'in_stock') {
                    throw new \Exception("Item {$item->barcode} is not available for sale");
                }
            }

            // Sum selling prices
            $sellingPrice = (float) $items->sum('selling_price');
            $manualDiscount = max(0, round($discount, 2));

            $offerEngine = app(OfferEngineService::class);
            $offerPayload = $offerEngine->resolveBestOffer(
                $shopId,
                $items->map(fn (Item $item) => [
                    'category' => $item->category,
                    'sub_category' => $item->sub_category,
                ])->all(),
                $sellingPrice,
                $offerSchemeId,
                $allowAutoOfferFallback,
            );
            $offerDiscount = (float) ($offerPayload['discount_amount'] ?? 0);
            $totalDiscount = min(round($sellingPrice, 2), round($manualDiscount + $offerDiscount, 2));

            $gstRate = $shop->gst_rate ?? config('business.gst_rate_default');

            // Create invoice
            $invoice = InvoiceAccountingService::createDraft([
                'customer_id'    => $customerId,
                'shop_id'        => $shopId,
                'gold_rate'      => 0,
                'gst_rate'       => $gstRate,
                'wastage_charge' => 0,
                'discount'       => $totalDiscount,
                'round_off'      => $roundOff,
            ]);

            // Invoice lines — one per item
            foreach ($items as $item) {
                $lineGst = round((float) $item->selling_price * ($gstRate / 100), 2);
                InvoiceItem::record([
                    'invoice_id'     => $invoice->id,
                    'item_id'        => $item->id,
                    'weight'         => $item->net_metal_weight,
                    'rate'           => $item->selling_price,
                    'making_charges' => $item->making_charges ?? 0,
                    'stone_amount'   => $item->stone_charges ?? 0,
                    'line_total'     => $item->selling_price,
                    'gst_rate'       => $gstRate,
                    'gst_amount'     => $lineGst,
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
                    $fineWt    = $mode === 'old_gold'
                        ? $netWt * ($purity / 24)
                        : $netWt * ($purity / 1000);
                    $ratePerG  = (float) ($p['metal_rate_per_gram'] ?? 0);

                    $attrs['metal_type']          = $mode === 'old_gold' ? 'gold' : 'silver';
                    $attrs['metal_gross_weight']  = $grossWt;
                    $attrs['metal_purity']        = $purity;
                    $attrs['metal_test_loss']     = $testLoss;
                    $attrs['metal_fine_weight']   = round($fineWt, 3);
                    $attrs['metal_rate_per_gram'] = $ratePerG;
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

                // Record MetalLot + MetalMovement for old-gold/old-silver received
                if (in_array($mode, ['old_gold', 'old_silver'])) {
                    $metalType = $mode === 'old_gold' ? 'gold' : 'silver';
                    $fineWeight = round($attrs['metal_fine_weight'] ?? 0, 3);

                    if ($fineWeight > 0) {
                        $lot = MetalLot::create([
                            'shop_id'              => $shopId,
                            'source'               => 'old_' . $metalType,
                            'purity'               => $attrs['metal_purity'],
                            'fine_weight_total'     => $fineWeight,
                            'fine_weight_remaining' => $fineWeight,
                            'cost_per_fine_gram'    => $attrs['metal_rate_per_gram'] ?? 0,
                        ]);

                        MetalMovement::record([
                            'shop_id'        => $shopId,
                            'from_lot_id'    => null,
                            'to_lot_id'      => $lot->id,
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
                                'shop_id'     => $shopId,
                                'customer_id' => $invoice->customer_id,
                                'gross_weight' => $attrs['metal_gross_weight'],
                                'purity'      => $attrs['metal_purity'],
                                'fine_gold'   => -1 * $fineWeight,
                                'type'        => 'old_metal_in',
                                'reference_type' => 'invoice',
                                'reference_id'   => $invoice->id,
                                'invoice_id'     => $invoice->id,
                            ]);
                        }
                    }
                }
            }

            // Validate payment total covers invoice total (1 rupee tolerance for rounding)
            if (abs($paymentTotal - (float) $invoice->total) > 1.00) {
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
                    'round_off'     => $roundOff,
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
                throw new \Exception('One or more items not found');
            }

            foreach ($items as $item) {
                if ($item->status !== 'in_stock') {
                    throw new \Exception("Item {$item->barcode} is not available for sale");
                }
            }

            $gstRate = $shop->gst_rate ?? config('business.gst_rate_default');
            $sellingPrice = (float) $items->sum('selling_price');
            $manualDiscount = max(0, round($discount, 2));

            $offerEngine = app(OfferEngineService::class);
            $offerPayload = $offerEngine->resolveBestOffer(
                $shopId,
                $items->map(fn (Item $item) => [
                    'category' => $item->category,
                    'sub_category' => $item->sub_category,
                ])->all(),
                $sellingPrice,
                $offerSchemeId,
                $allowAutoOfferFallback,
            );
            $offerDiscount = (float) ($offerPayload['discount_amount'] ?? 0);
            $totalDiscount = min(round($sellingPrice, 2), round($manualDiscount + $offerDiscount, 2));

            $invoice = InvoiceAccountingService::createDraft([
                'customer_id'    => $customerId,
                'shop_id'        => $shopId,
                'gold_rate'      => 0,
                'gst_rate'       => $gstRate,
                'wastage_charge' => 0,
                'discount'       => $totalDiscount,
                'round_off'      => $roundOff,
            ]);

            foreach ($items as $item) {
                $lineGst = round((float) $item->selling_price * ($gstRate / 100), 2);
                InvoiceItem::record([
                    'invoice_id'     => $invoice->id,
                    'item_id'        => $item->id,
                    'weight'         => $item->net_metal_weight,
                    'rate'           => $item->selling_price,
                    'making_charges' => $item->making_charges ?? 0,
                    'stone_amount'   => $item->stone_charges ?? 0,
                    'line_total'     => $item->selling_price,
                    'gst_rate'       => $gstRate,
                    'gst_amount'     => $lineGst,
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
                    'round_off'   => $roundOff,
                ],
            ]);

            return $invoice;
        });
    }
}
