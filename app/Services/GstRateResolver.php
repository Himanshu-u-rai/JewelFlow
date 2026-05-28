<?php

namespace App\Services;

use App\Models\GstCategory;
use App\Models\Shop;
use App\Services\MetalRegistry;

class GstRateResolver
{
    public function resolveForShop(Shop|int|null $shop, ?string $metalType = null, ?float $overrideRate = null): float
    {
        if ($overrideRate !== null) {
            return max(0, (float) $overrideRate);
        }

        $shopModel = $shop instanceof Shop
            ? $shop
            : ($shop ? Shop::find((int) $shop) : null);

        $default = (float) (config('business.gst_rate_default') ?? 3.0);
        if (!$shopModel) {
            return $default;
        }

        return GstCategory::resolveRate(
            (int) $shopModel->id,
            $this->normalizeMetalType($metalType),
            $default
        );
    }

    public function normalizeMetalType(?string $metalType): ?string
    {
        // Phase 1: delegates authority to MetalRegistry. NULL is returned
        // for unsupported metals so callers fall back to the default rate
        // rather than crashing — this matches legacy behavior for unknown
        // metal_type values on legacy invoice lines.
        if ($metalType === null || trim((string) $metalType) === '') {
            return null;
        }
        $normalized = MetalRegistry::normalize((string) $metalType);
        return MetalRegistry::isSupported($normalized) ? $normalized : null;
    }
}

