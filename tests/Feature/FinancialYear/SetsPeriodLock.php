<?php

namespace Tests\Feature\FinancialYear;

use Illuminate\Support\Facades\DB;

/**
 * Shared fixture for the period-lock coverage tests. The lock sits 60 days in the past so that "today" is
 * always open and the dates around the boundary are real calendar days.
 */
trait SetsPeriodLock
{
    protected function lockDate(): string
    {
        return now()->subDays(60)->toDateString();
    }

    protected function insideLock(): string
    {
        return now()->subDays(70)->toDateString();
    }

    protected function dayAfterLock(): string
    {
        return now()->subDays(59)->toDateString();
    }

    protected function setLock(int $shopId, ?string $date = null): void
    {
        DB::table('shop_rules')->updateOrInsert(['shop_id' => $shopId], [
            'default_purity' => '22K', 'default_making_type' => 'per_gram', 'default_making_value' => 0,
            'test_loss_percent' => 0, 'buyback_percent' => 100, 'rounding_precision' => 2,
            'financial_lock_date' => $date ?? $this->lockDate(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
