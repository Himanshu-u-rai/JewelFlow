<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Item;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\CashTransaction;
use App\Models\CustomerGoldTransaction;
use App\Services\InvoiceAccountingService;
use App\Services\SubscriptionGateService;
use Illuminate\Validation\ValidationException;

class SalesService
{
    /**
     * Sell one item, supporting:
     *  - discount & round_off
     *  - split payments (cash / upi / bank / old_gold / old_silver / other)
     *
     * $payments = [
     *   ['mode' => 'cash', 'amount' => 3000],
     *   ['mode' => 'upi',  'amount' => 2000, 'reference' => 'UPI-REF-123'],
     *   ['mode' => 'old_gold', 'amount' => 5000, 'metal_gross_weight' => 2.5,
     *     'metal_purity' => 22, 'metal_test_loss' => 2, 'metal_rate_per_gram' => ...],
     * ]
     */
    public static function sellItem(
        $customerId,
        $itemId,
        $goldRate,
        $making,
        $stone,
        float $discount = 0,
        float $roundOff = 0,
        array $payments = [],
        float $creditOffset = 0,
        array $rateContext = []
    ) {
        return DB::transaction(function () use (
            $customerId, $itemId, $goldRate, $making, $stone,
            $discount, $roundOff, $payments, $creditOffset, $rateContext
        ) {
            $shop = auth()->user()->shop;
            if (!$shop || !$shop->isManufacturer()) {
                throw new \LogicException('Manufacturer edition required for this sale flow.');
            }
            $shopId = $shop->id;
            SubscriptionGateService::assertShopWritable($shopId);

            // Lock the item so it can't be sold twice
            $item = Item::where('shop_id', $shopId)
                ->lockForUpdate()
                ->findOrFail($itemId);

            if ($item->status !== 'in_stock') {
                throw ValidationException::withMessages([
                    'item_id' => 'Item is not available for sale.',
                ]);
            }

            $fineGold = $item->net_metal_weight * ($item->purity / 24);

            // Load customer gold balance
            $customerGold = CustomerGoldTransaction::where('shop_id', $shopId)
                ->where('customer_id', $customerId)
                ->sum('fine_gold');

            $goldToUse = min(max(0, $customerGold), $fineGold);

            // Calculate wastage recovery charge
            $wastageRecoveryPercent = $shop->wastage_recovery_percent ?? 100;
            $wastageFineGold = $item->wastage;
            $wastageCharge = round(($wastageFineGold * $goldRate * $wastageRecoveryPercent) / 100, 2);

            // Calculate subtotal and GST
            $subtotal = round(($fineGold * $goldRate) + $making + $stone, 2);
            if ($creditOffset > 0) {
                $subtotal = round(max(0, $subtotal - $creditOffset), 2);
            }
            $gstRate = $shop->gst_rate ?? config('business.gst_rate_default');
            $gst = round($subtotal * ($gstRate / 100), 2);
            $total = round($subtotal + $gst + $wastageCharge - $discount + $roundOff, 2);

            // Create invoice draft
            $invoice = InvoiceAccountingService::createDraft([
                'customer_id' => $customerId,
                'shop_id'     => $shopId,
                'gold_rate'   => $goldRate,
                'gst_rate'    => $gstRate,
                'wastage_charge' => $wastageCharge,
                'discount'    => $discount,
                'round_off'   => $roundOff,
            ]);

            // Create invoice line
            InvoiceItem::record([
                'invoice_id'     => $invoice->id,
                'item_id'        => $item->id,
                'weight'         => $item->net_metal_weight,
                'rate'           => $goldRate,
                'making_charges' => $making,
                'stone_amount'   => $stone,
                'line_total'     => ($fineGold * $goldRate) + $making + $stone,
            ]);

            $invoice = InvoiceAccountingService::finalizeDraft($invoice, (float) $gstRate);

            // ── Record payment splits ───────────────────────────────
            // If no payments array provided, assume full cash (backward compat)
            if (empty($payments)) {
                $payments = [['mode' => 'cash', 'amount' => (float) $invoice->total]];
            }

            $cashTotal = 0;
            $paymentTotal = 0;
            foreach ($payments as $p) {
                $mode   = $p['mode'];
                $amount = round((float) ($p['amount'] ?? 0), 2);
                if ($amount <= 0) continue;
                $paymentTotal += $amount;

                $attrs = [
                    'invoice_id' => $invoice->id,
                    'shop_id'    => $shopId,
                    'mode'       => $mode,
                    'amount'     => $amount,
                    'reference'  => $p['reference'] ?? null,
                    'note'       => $p['note'] ?? null,
                ];

                // Metal payments (old gold / old silver)
                if (in_array($mode, ['old_gold', 'old_silver'])) {
                    $grossWt   = (float) ($p['metal_gross_weight'] ?? 0);
                    $purity    = (float) ($p['metal_purity'] ?? 0);
                    $testLoss  = (float) ($p['metal_test_loss'] ?? 0);
                    $netWt     = $grossWt * (1 - ($testLoss / 100));
                    $fineWt    = $mode === 'old_gold'
                        ? $netWt * ($purity / 24)
                        : $netWt * ($purity / 1000); // silver millesimal
                    $ratePerG  = (float) ($p['metal_rate_per_gram'] ?? 0);

                    $attrs['metal_type']           = $mode === 'old_gold' ? 'gold' : 'silver';
                    $attrs['metal_gross_weight']   = $grossWt;
                    $attrs['metal_purity']         = $purity;
                    $attrs['metal_test_loss']      = $testLoss;
                    $attrs['metal_fine_weight']    = round($fineWt, 3);
                    $attrs['metal_rate_per_gram']  = $ratePerG;

                    // Create gold lot for old gold (silver stays as cash value only)
                    if ($mode === 'old_gold' && $fineWt > 0) {
                        $lot = MetalLot::create([
                            'source' => 'exchange',
                            'shop_id' => $shopId,
                            'purity' => $purity,
                            'fine_weight_total' => $fineWt,
                            'fine_weight_remaining' => $fineWt,
                            'cost_per_fine_gram' => $fineWt > 0 ? $amount / $fineWt : 0,
                        ]);

                        MetalMovement::record([
                            'from_lot_id' => null,
                            'to_lot_id' => $lot->id,
                            'shop_id' => $shopId,
                            'fine_weight' => $fineWt,
                            'type' => 'exchange',
                            'reference_type' => 'invoice',
                            'reference_id' => $invoice->id,
                            'user_id' => auth()->id(),
                        ]);
                    }
                }

                InvoicePayment::record($attrs);

                // Accumulate cash-equivalent for CashTransaction
                if (in_array($mode, ['cash', 'upi', 'bank', 'other'])) {
                    $cashTotal += $amount;
                }
            }

            // Record cash inflow (money the shop received)
            if ($cashTotal > 0) {
                CashTransaction::record([
                    'shop_id'     => $shopId,
                    'user_id'     => auth()->id(),
                    'type'        => 'in',
                    'amount'      => $cashTotal,
                    'source_type' => 'invoice',
                    'source_id'   => $invoice->id,
                    'invoice_id'  => $invoice->id,
                    'description' => "Sale - Invoice {$invoice->invoice_number}",
                ]);
            }

            // Validate payment total covers invoice (1 rupee tolerance for rounding)
            if ($creditOffset <= 0 && abs($paymentTotal - (float) $invoice->total) > 1.00) {
                throw new \Exception("Payment total (₹{$paymentTotal}) does not match invoice total (₹{$invoice->total})");
            }

            // Deduct customer gold if used
            if ($goldToUse > 0) {
                CustomerGoldTransaction::record([
                    'shop_id'        => $shopId,
                    'customer_id'    => $customerId,
                    'fine_gold'      => -$goldToUse,
                    'type'           => 'sale_offset',
                    'reference_type' => 'invoice',
                    'reference_id'   => $invoice->id,
                    'invoice_id'     => $invoice->id,
                ]);

                \App\Models\AuditLog::create([
                    'shop_id'    => $shopId,
                    'user_id'    => auth()->id(),
                    'action'     => 'customer_gold_adjust',
                    'model_type' => 'invoice',
                    'model_id'   => $invoice->id,
                    'data'       => ['gold_used' => $goldToUse],
                ]);
            }

            // Mark item as sold
            $item->status = 'sold';
            $item->save();

            // Audit log
            \App\Models\AuditLog::create([
                'shop_id'    => $shopId,
                'user_id'    => auth()->id(),
                'action'     => 'sale',
                'model_type' => 'invoice',
                'model_id'   => $invoice->id,
                'data'       => [
                    'customer_id' => $customerId,
                    'item_id'     => $itemId,
                    'total'       => (float) $invoice->total,
                    'discount'    => $discount,
                    'round_off'   => $roundOff,
                    'payments'    => collect($payments)->map(fn($p) => [
                        'mode' => $p['mode'], 'amount' => $p['amount'] ?? 0,
                    ])->toArray(),
                ],
            ]);

            return $invoice;
        });
    }
}
