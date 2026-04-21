<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\CashTransaction;

class BuybackService
{
    public static function buyGold($grossWeight, $purity, $testLossPercent = 0, float $goldRate = 0)
    {
        // Backward compat: fall back to request if caller didn't pass rate
        if ($goldRate <= 0) {
            $goldRate = (float) request('gold_rate', 0);
        }

        return DB::transaction(function () use ($grossWeight, $purity, $testLossPercent, $goldRate) {

            $shopId = auth()->user()->shop_id;

            // Calculate net weight after test loss
            $netWeight = $grossWeight * (1 - ($testLossPercent / 100));

            // Calculate fine gold
            $fineGold = $netWeight * ($purity / 24);

            if ($fineGold <= 0) {
                throw new \Exception('Fine gold weight must be greater than zero.');
            }

            $cashPaid = $fineGold * $goldRate;

            // Create new gold lot
            $lot = MetalLot::create([
                'source' => 'buyback',
                'shop_id' => $shopId,
                'purity' => $purity,
                'fine_weight_total' => $fineGold,
                'fine_weight_remaining' => $fineGold,
                'cost_per_fine_gram' => $cashPaid / $fineGold,
            ]);

            CashTransaction::record([
                'shop_id' => $shopId,
                'user_id' => auth()->id(),
                'type' => 'out',
                'amount' => $cashPaid,
                'source_type' => 'buyback',
                'source_id' => $lot->id,
                'description' => "Gold Buyback - Lot {$lot->lot_number}",
            ]);

            // Record gold entry
            MetalMovement::record([
                'from_lot_id' => null,
                'to_lot_id' => $lot->id,
                'shop_id' => $shopId,
                'fine_weight' => $fineGold,
                'type' => 'buyback',
                'reference_type' => 'buyback',
                'reference_id' => $lot->id,
                'user_id' => auth()->id()
            ]);

            return $lot;
        });
    }
}
