<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use App\Services\SubscriptionGateService;
use LogicException;

class ItemManufacturingService
{
    /**
     * Manufacture an item from a lot, deducting gold and writing ledger entries.
     *
     * Expected input keys:
     * barcode, design, category, sub_category, gross_weight, stone_weight,
     * purity, wastage_percent, making_charges, stone_charges, image, product_id.
     */
    public function manufacture(int $shopId, int $userId, array $input): Item
    {
        $shopType = Shop::whereKey($shopId)->value('shop_type');
        if ($shopType !== 'manufacturer') {
            throw new LogicException('Manufacturing is available only for manufacturer edition.');
        }

        SubscriptionGateService::assertShopWritable($shopId);
        $this->assertFinancialLock($shopId);

        $stoneWeight = (float) ($input['stone_weight'] ?? 0);
        $grossWeight = (float) $input['gross_weight'];
        $purity = (float) $input['purity'];
        $wastagePercent = (float) ($input['wastage_percent'] ?? 0);
        $makingCharges = (float) ($input['making_charges'] ?? 0);
        $stoneCharges = (float) ($input['stone_charges'] ?? 0);

        $netMetal = round($grossWeight - $stoneWeight, 6);
        if ($netMetal <= 0) {
            throw new LogicException('Net metal weight must be greater than zero.');
        }

        $fineRequired = round($netMetal * ($purity / 24), 6);
        $wastage = round($fineRequired * ($wastagePercent / 100), 6);
        $totalFineNeeded = round($fineRequired + $wastage, 6);

        return DB::transaction(function () use (
            $shopId,
            $userId,
            $input,
            $netMetal,
            $fineRequired,
            $wastage,
            $totalFineNeeded,
            $makingCharges,
            $stoneCharges,
            $stoneWeight
        ) {
            $lot = MetalLot::where('shop_id', $shopId)
                ->where('id', $input['metal_lot_id'])
                ->lockForUpdate()
                ->first();

            if (!$lot) {
                throw new LogicException('Invalid gold lot selected.');
            }

            if ((float) $lot->fine_weight_remaining < $totalFineNeeded) {
                throw new LogicException(
                    'Insufficient fine gold in lot #' . $lot->lot_number .
                    '. Required: ' . number_format($totalFineNeeded, 3) .
                    'g, Available: ' . number_format((float) $lot->fine_weight_remaining, 3) . 'g'
                );
            }

            $goldCost = $totalFineNeeded * (float) ($lot->cost_per_fine_gram ?? 0);
            $totalCost = round($goldCost + $makingCharges + $stoneCharges, 2);

            $item = Item::create([
                'shop_id' => $shopId,
                'barcode' => $input['barcode'],
                'product_id' => $input['product_id'] ?? null,
                'design' => $input['design'] ?? null,
                'image' => $input['image'] ?? null,
                'category' => $input['category'],
                'sub_category' => $input['sub_category'] ?? null,
                'gross_weight' => $input['gross_weight'],
                'stone_weight' => $stoneWeight,
                'net_metal_weight' => $netMetal,
                'purity' => $input['purity'],
                'metal_lot_id' => $lot->id,
                'wastage' => $wastage,
                'making_charges' => $makingCharges,
                'stone_charges' => $stoneCharges,
                'cost_price' => $totalCost,
                'status' => 'in_stock',
            ]);

            $lot->fine_weight_remaining = round((float) $lot->fine_weight_remaining - $totalFineNeeded, 6);
            $lot->save();

            MetalMovement::record([
                'shop_id' => $shopId,
                'from_lot_id' => $lot->id,
                'to_lot_id' => null,
                'fine_weight' => $fineRequired,
                'type' => 'manufacture',
                'reference_type' => 'item',
                'reference_id' => $item->id,
                'user_id' => $userId,
            ]);

            if ($wastage > 0) {
                MetalMovement::record([
                    'shop_id' => $shopId,
                    'from_lot_id' => $lot->id,
                    'to_lot_id' => null,
                    'fine_weight' => $wastage,
                    'type' => 'wastage',
                    'reference_type' => 'item',
                    'reference_id' => $item->id,
                    'user_id' => $userId,
                ]);
            }

            AuditLog::create([
                'shop_id' => $shopId,
                'user_id' => $userId,
                'action' => 'item_created',
                'model_type' => 'item',
                'model_id' => $item->id,
                'description' => 'Item manufactured from lot #' . $lot->lot_number,
                'data' => [
                    'barcode' => $item->barcode,
                    'lot_id' => $lot->id,
                    'lot_number' => $lot->lot_number,
                    'fine_gold_used' => $totalFineNeeded,
                    'wastage' => $wastage,
                ],
            ]);

            return $item;
        });
    }

    private function assertFinancialLock(int $shopId): void
    {
        $lockDate = DB::table('shop_rules')->where('shop_id', $shopId)->value('financial_lock_date');
        if ($lockDate && now()->toDateString() <= $lockDate) {
            throw new LogicException("Financial lock is active through {$lockDate}.");
        }
    }

}
