<?php

namespace App\Services;

use App\Models\MetalLot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OldMetalWeeklyLotService
{
    /**
     * Returns the weekly lot for the given shop + metal type + date,
     * creating it if one does not exist yet.
     *
     * Race-safe: UNIQUE index on (shop_id, source, iso_year, iso_week)
     * backs the firstOrCreate so duplicate-insert conflicts are resolved
     * by the database without a separate advisory lock.
     */
    public function findOrCreateForDate(int $shopId, string $metalType, Carbon $date): MetalLot
    {
        $isoYear = (int) $date->isoWeekYear;
        $isoWeek = (int) $date->isoWeek;
        $source  = $metalType === 'gold'
            ? MetalLot::SOURCE_OLD_GOLD_WEEKLY
            : MetalLot::SOURCE_OLD_SILVER_WEEKLY;

        return MetalLot::firstOrCreate(
            [
                'shop_id'  => $shopId,
                'source'   => $source,
                'iso_year' => $isoYear,
                'iso_week' => $isoWeek,
            ],
            [
                'metal_type'            => $metalType,
                'purity'                => 0,   // mixed purities; 0 = aggregate lot
                'fine_weight_total'     => 0,
                'fine_weight_remaining' => 0,
                'cost_per_fine_gram'    => 0,
                'is_dispatched'         => false,
            ]
        );
    }

    /**
     * Atomically accumulates fine weight onto the weekly lot and recomputes
     * the weighted-average cost per fine gram.
     *
     * Must be called inside the caller's DB::transaction (RetailerSalesService
     * already wraps everything in one). The lockForUpdate() here serialises
     * concurrent sales in the same week at the DB level.
     */
    public function addFineWeight(MetalLot $weeklyLot, float $fineWeight, float $grossValue): void
    {
        $lot = MetalLot::where('id', $weeklyLot->id)->lockForUpdate()->firstOrFail();

        $prevTotal = (float) $lot->fine_weight_total;
        $prevCost  = (float) $lot->cost_per_fine_gram;
        $newTotal  = $prevTotal + $fineWeight;
        $newCost   = $newTotal > 0
            ? round((($prevTotal * $prevCost) + $grossValue) / $newTotal, 4)
            : 0;

        DB::table('metal_lots')->where('id', $lot->id)->update([
            'fine_weight_total'     => DB::raw('fine_weight_total + ' . $fineWeight),
            'fine_weight_remaining' => DB::raw('fine_weight_remaining + ' . $fineWeight),
            'cost_per_fine_gram'    => $newCost,
            'updated_at'            => now(),
        ]);
    }

    /**
     * Marks the lot as dispatched. Idempotent guard prevents double-dispatch.
     */
    public function dispatch(MetalLot $weeklyLot, string $notes): void
    {
        abort_if($weeklyLot->is_dispatched, 422, 'Lot already dispatched.');

        $weeklyLot->update([
            'is_dispatched'  => true,
            'dispatched_at'  => now(),
            'dispatch_notes' => $notes,
        ]);
    }
}
