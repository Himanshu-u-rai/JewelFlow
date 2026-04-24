<?php

namespace App\Services;

use App\Models\ReorderRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReorderAlertService
{
    /**
     * Return all active alerts for a shop (rules whose stock is below threshold).
     *
     * Passing $shopId explicitly makes this safe to call from scheduled jobs
     * and console commands where Auth::user() is not available.
     */
    public function getAlerts(int $shopId): Collection
    {
        // withoutTenant() lets us scope by $shopId directly, making this
        // safe in all call contexts (web request, console, queued jobs).
        $rules = ReorderRule::withoutTenant()
            ->where('shop_id', $shopId)
            ->with('vendor')
            ->active()
            ->get();

        return $rules
            ->map(fn (ReorderRule $rule) => $rule->checkStock())
            ->filter(fn (array $result) => $result['below_threshold'])
            ->values();
    }

    /**
     * Cached alert count for a shop — used by the nav badge.
     * TTL: 5 minutes. Stale-by-5-min is acceptable for a badge.
     */
    public function alertCount(int $shopId): int
    {
        return (int) Cache::remember("reorder_alerts_{$shopId}", 300, fn () =>
            $this->getAlerts($shopId)->count()
        );
    }

    /**
     * Clear the cached alert count for a shop.
     * Call after creating, updating, or deleting a reorder rule.
     */
    public function clearCache(int $shopId): void
    {
        Cache::forget("reorder_alerts_{$shopId}");
    }
}
