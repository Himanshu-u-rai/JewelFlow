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
     * Cached alerts as plain rows — read by the header bell and the nav badges,
     * so they always agree. TTL: 5 minutes. Stale-by-5-min is acceptable for a badge.
     */
    public function cachedAlerts(int $shopId): array
    {
        return Cache::remember("reorder_alert_rows_{$shopId}", 300, fn () => $this->getAlerts($shopId)
            ->map(fn (array $alert): array => [
                'category' => $alert['category'] ?: null,
                'sub_category' => $alert['sub_category'] ?: null,
                'current_stock' => (int) $alert['current_stock'],
                'threshold' => (int) $alert['threshold'],
                'vendor_name' => $alert['vendor']?->name,
            ])
            ->all());
    }

    public function alertCount(int $shopId): int
    {
        return count($this->cachedAlerts($shopId));
    }

    /**
     * Clear the cached alerts for a shop.
     * Call after creating, updating, or deleting a reorder rule.
     */
    public function clearCache(int $shopId): void
    {
        Cache::forget("reorder_alert_rows_{$shopId}");
    }
}
