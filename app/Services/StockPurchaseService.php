<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockPurchase;
use Illuminate\Support\Facades\DB;
use LogicException;

class StockPurchaseService
{
    public function confirmPurchase(StockPurchase $purchase, int $userId): void
    {
        if (! $purchase->isDraft()) {
            throw new LogicException('Only draft purchases can be confirmed.');
        }

        if ($purchase->lines()->count() === 0) {
            throw new LogicException('Purchase must have at least one line item to confirm.');
        }

        DB::transaction(function () use ($purchase, $userId): void {
            // Re-fetch with row lock so concurrent requests don't both confirm.
            $purchase = StockPurchase::where('id', $purchase->id)->lockForUpdate()->firstOrFail();
            if (! $purchase->isDraft()) {
                throw new LogicException('Purchase was already confirmed by another request.');
            }

            $purchase->load('lines');

            $subtotal    = $purchase->lines->sum(fn ($l) => (float) $l->purchase_line_amount);
            $subtotal    = round($subtotal - (float) $purchase->labour_discount, 2);
            $cgstAmount  = round($subtotal * (float) $purchase->cgst_rate / 100, 2);
            $sgstAmount  = round($subtotal * (float) $purchase->sgst_rate / 100, 2);
            $igstAmount  = round($subtotal * (float) $purchase->igst_rate / 100, 2);
            $totalAmount = round($subtotal + $cgstAmount + $sgstAmount + $igstAmount + (float) $purchase->tcs_amount, 2);

            $purchase->subtotal_amount      = $subtotal;
            $purchase->cgst_amount          = $cgstAmount;
            $purchase->sgst_amount          = $sgstAmount;
            $purchase->igst_amount          = $igstAmount;
            $purchase->total_amount         = $totalAmount;
            $purchase->status               = 'confirmed';
            $purchase->confirmed_at         = now();
            $purchase->confirmed_by_user_id = $userId;
            $purchase->save();

            AuditLog::create([
                'shop_id'    => $purchase->shop_id,
                'user_id'    => $userId,
                'action'     => 'purchase_confirmed',
                'model_type' => 'stock_purchase',
                'model_id'   => $purchase->id,
                'data'       => [
                    'purchase_number' => $purchase->purchase_number,
                    'total_amount'    => $totalAmount,
                ],
            ]);
        });
    }

    public function addToInventory(StockPurchase $purchase, int $userId): int
    {
        if (! $purchase->isConfirmed()) {
            throw new LogicException('Only confirmed purchases can be added to inventory.');
        }

        $itemsCreated = 0;

        DB::transaction(function () use ($purchase, $userId, &$itemsCreated): void {
            // Re-fetch with row lock so concurrent requests don't both stock.
            $purchase = StockPurchase::where('id', $purchase->id)->lockForUpdate()->firstOrFail();
            if (! $purchase->isConfirmed()) {
                throw new LogicException('Purchase is no longer in confirmed state.');
            }

            $purchase->load('lines');

            foreach ($purchase->lines as $index => $line) {
                if ($line->item_id !== null) {
                    continue;
                }

                if ($line->line_type === 'bullion_reserve') {
                    continue;
                }

                $barcode = $line->barcode ?: $this->generateBarcodeForLine($purchase->id, $index + 1);

                if (Item::where('shop_id', $purchase->shop_id)->where('barcode', $barcode)->exists()) {
                    $barcode = $barcode . '-' . ($index + 1);
                }

                $item = Item::create([
                    'shop_id'                 => $purchase->shop_id,
                    'stock_purchase_id'       => $purchase->id,
                    'barcode'                 => $barcode,
                    'design'                  => $line->design,
                    'category'                => $line->category ?? ($line->line_type === 'ornament' ? 'Ornament' : 'Bullion'),
                    'sub_category'            => $line->sub_category,
                    'metal_type'              => $line->metal_type,
                    'purity'                  => $line->purity,
                    'gross_weight'            => $line->gross_weight,
                    'stone_weight'            => $line->stone_weight,
                    'net_metal_weight'        => $line->net_metal_weight,
                    'huid'                    => $line->huid,
                    'hallmark_date'           => $line->hallmark_date,
                    'making_charges'          => $line->making_charges,
                    'stone_charges'           => $line->stone_charges,
                    'hallmark_charges'        => $line->hallmark_charges,
                    'rhodium_charges'         => $line->rhodium_charges,
                    'other_charges'           => $line->other_charges,
                    'cost_price'              => round((float) $line->net_metal_weight * (float) $line->purchase_rate_per_gram, 2),
                    'selling_price'           => null,
                    'source'                  => 'purchased',
                    'status'                  => 'in_stock',
                    'pricing_review_required' => true,
                    'vendor_id'               => $purchase->vendor_id,
                ]);

                $line->item_id = $item->id;
                $line->barcode = $barcode;
                $line->save();

                $itemsCreated++;
            }

            $purchase->status           = 'stocked';
            $purchase->stocked_at       = now();
            $purchase->stocked_by_user_id = $userId;
            $purchase->save();

            AuditLog::create([
                'shop_id'    => $purchase->shop_id,
                'user_id'    => $userId,
                'action'     => 'purchase_stocked',
                'model_type' => 'stock_purchase',
                'model_id'   => $purchase->id,
                'data'       => [
                    'purchase_number' => $purchase->purchase_number,
                    'items_created'   => $itemsCreated,
                ],
            ]);
        });

        return $itemsCreated;
    }

    public function deletePurchase(StockPurchase $purchase): void
    {
        if (! $purchase->isDraft()) {
            throw new LogicException('Only draft purchases can be deleted.');
        }

        $purchase->delete();
    }

    private function generateBarcodeForLine(int $purchaseId, int $lineIndex): string
    {
        return 'PUR' . str_pad((string) $purchaseId, 6, '0', STR_PAD_LEFT) . str_pad((string) $lineIndex, 3, '0', STR_PAD_LEFT);
    }
}
