<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Item;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Shared setup for the year-end snapshot tests. "Today" is 2026-11-16 IST, so the last finished year is 2025-26. */
trait SnapshotTestHelpers
{
    private function at(string $ist): void
    {
        $this->travelTo(CarbonImmutable::parse($ist, 'Asia/Kolkata'));
    }

    private function today(): void
    {
        $this->at('2026-11-16 12:00:00');
    }

    private function setStatus(int $id, string $status): void
    {
        Item::withoutTenant()->whereKey($id)->first()->update(['status' => $status]);
    }

    /** A closing rate row for the shop on a business day (metal_rates is append-only: insert only). */
    private function rate(int $shopId, string $date, string $metal, float $purity, float $perGram): void
    {
        DB::table('metal_rates')->insert([
            'shop_id' => $shopId, 'business_date' => $date, 'metal_type' => $metal,
            'purity' => (string) $purity, 'purity_value' => $purity, 'rate_per_gram' => $perGram,
            'source' => 'manual', 'is_override' => DB::raw('false'), 'fetched_at' => now(), 'created_at' => now(),
        ]);
    }
}
