<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class GstCategory extends Model
{
    use BelongsToShop;

    protected $table = 'gst_categories';

    protected $fillable = [
        'shop_id',
        'name',
        'rate_pct',
        'metal_type',
        'is_default',
    ];

    protected $casts = [
        'rate_pct'   => 'decimal:2',
        'is_default' => 'boolean',
    ];

    /**
     * Resolve the GST rate for a given metal type on a shop.
     * Priority: metal-type-specific category > is_default category > fallback.
     */
    public static function resolveRate(int $shopId, ?string $metalType, float $fallback = 3.0): float
    {
        // Deterministic order (oldest id first) so the result is stable even if a
        // duplicate ever slips past the unique index (#1 defense-in-depth).
        $categories = static::where('shop_id', $shopId)->orderBy('id')->get();

        if ($categories->isEmpty()) {
            return $fallback;
        }

        // #2 — ignore categories whose metal the shop has since DISABLED in
        // Materials, so a stale override can't keep applying. Catch-all
        // categories (metal_type NULL) are always kept.
        $enabled = \App\Services\MetalRegistry::enabledMetalsForShop($shopId);
        $categories = $categories->filter(function ($c) use ($enabled) {
            $normalized = self::normalizeMetalType($c->metal_type);
            return $normalized === null || in_array($normalized, $enabled, true);
        })->values();

        if ($categories->isEmpty()) {
            return $fallback;
        }

        $normalizedMetal = self::normalizeMetalType($metalType);

        // 1. Exact metal-type match
        if ($normalizedMetal) {
            $specific = $categories->first(
                fn ($c) => self::normalizeMetalType($c->metal_type) === $normalizedMetal
            );
            if ($specific) {
                return (float) $specific->rate_pct;
            }
        }

        // 2. Default category (catch-all)
        $default = $categories->first(fn ($c) => $c->is_default);
        if ($default) {
            return (float) $default->rate_pct;
        }

        // 3. No metal-specific match and no default category → fall back to the
        //    caller-supplied rate (the shop's flat GST rate). Never return an
        //    unrelated metal's rate (e.g. don't tax silver at the gold category).
        return $fallback;
    }

    private static function normalizeMetalType(?string $metalType): ?string
    {
        // Phase 1: delegates authority to MetalRegistry. NULL is returned for
        // unsupported metals so resolveRate falls back to the default rate.
        if ($metalType === null || trim((string) $metalType) === '') {
            return null;
        }
        $normalized = \App\Services\MetalRegistry::normalize((string) $metalType);
        return \App\Services\MetalRegistry::isSupported($normalized) ? $normalized : null;
    }
}
