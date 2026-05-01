<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Item;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PosSearchCacheService
{
    public static function items(int $shopId, ?string $search = null): Collection
    {
        $search = self::normalizeSearch($search);
        $cacheKey = self::itemsCacheKey($shopId, $search);
        $ttlSeconds = (int) config('performance.cache.pos_search_ttl_seconds', 120);

        return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($shopId, $search) {
            return TenantContext::runFor($shopId, function () use ($search) {
                $query = Item::query()
                    ->where('status', 'in_stock');

                if ($search !== '') {
                    $query->where(function ($q) use ($search) {
                        $q->where('barcode', 'ilike', '%' . $search . '%')
                            ->orWhere('design', 'ilike', '%' . $search . '%');
                    });
                }

                return $query
                    ->orderByDesc('updated_at')
                    ->limit(20)
                    ->get();
            });
        });
    }

    public static function customers(int $shopId, ?string $search = null): Collection
    {
        $search = self::normalizeSearch($search);
        $cacheKey = self::customersCacheKey($shopId, $search);
        $ttlSeconds = (int) config('performance.cache.pos_search_ttl_seconds', 120);

        return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($shopId, $search) {
            return TenantContext::runFor($shopId, function () use ($search) {
                $query = Customer::query();

                if ($search !== '') {
                    $query->where(function ($q) use ($search) {
                        $q->where('first_name', 'ilike', '%' . $search . '%')
                            ->orWhere('last_name', 'ilike', '%' . $search . '%')
                            ->orWhere('mobile', 'like', '%' . $search . '%')
                            ->orWhereRaw("CONCAT(first_name, ' ', COALESCE(last_name, '')) ILIKE ?", ['%' . $search . '%']);
                    });

                    // Rank exact/prefix matches above mid-string matches so the
                    // most relevant result appears first regardless of recency.
                    $query->orderByRaw("
                        CASE
                            WHEN first_name ILIKE ? THEN 0
                            WHEN CONCAT(first_name, ' ', COALESCE(last_name, '')) ILIKE ? THEN 1
                            WHEN mobile LIKE ? THEN 2
                            ELSE 3
                        END
                    ", [$search . '%', $search . '%', $search . '%']);
                }

                return $query
                    ->orderByDesc('updated_at')
                    ->limit(50)
                    ->get();
            });
        });
    }

    public static function itemsCacheKey(int $shopId, ?string $search = null): string
    {
        $search = self::normalizeSearch($search);

        return "pos:items:{$shopId}:" . sha1($search);
    }

    public static function customersCacheKey(int $shopId, ?string $search = null): string
    {
        $search = self::normalizeSearch($search);

        return "pos:customers:{$shopId}:" . sha1($search);
    }

    private static function normalizeSearch(?string $search): string
    {
        $search = trim((string) $search);
        if ($search === '') {
            return '';
        }

        return mb_strtolower($search);
    }
}

