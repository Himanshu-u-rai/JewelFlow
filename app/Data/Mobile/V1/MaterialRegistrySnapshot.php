<?php

namespace App\Data\Mobile\V1;

use App\Services\MetalRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Top-level material registry snapshot — the canonical payload returned by
 * GET /api/mobile/v1/registry/materials.
 *
 * This DTO is the SINGLE producer of the registry payload. All capability
 * data flows from MetalRegistry — no other service is consulted (no
 * ShopPricingService, no ReferencePriceService). The registry describes
 * capabilities; it never reads rates or reference prices.
 */
#[TypeScript]
class MaterialRegistrySnapshot extends Data
{
    public function __construct(
        public string $registry_version,
        public int $shop_id,
        /** @var array<int, string> */
        public array $enabled_metals,
        /** @var array<string, MetalDescriptor> */
        public array $metals,
        public ?StoneDescriptor $stones,
    ) {}

    /**
     * Build the snapshot for a shop. Reads MetalRegistry + shop-scoped
     * purity profiles only.
     */
    public static function forShop(int $shopId): self
    {
        $enabledMetals = MetalRegistry::enabledMetalsForShop($shopId);

        $metals = [];
        foreach ($enabledMetals as $metal) {
            $metals[$metal] = self::buildMetalDescriptor($shopId, $metal);
        }

        return new self(
            registry_version: MetalRegistry::registryVersion(),
            shop_id: $shopId,
            enabled_metals: array_values($enabledMetals),
            metals: $metals,
            stones: new StoneDescriptor(),
        );
    }

    private static function buildMetalDescriptor(int $shopId, string $metal): MetalDescriptor
    {
        $isAccounting = MetalRegistry::purityIsAccountingTruth($metal);

        // fine_weight_supported is true iff the registry can produce a
        // multiplier — i.e. for accounting-truth metals. We probe with a
        // sample purity (24) just to confirm the registry returns non-null;
        // the sample value itself never escapes this method.
        $fineWeightSupported = MetalRegistry::fineWeightMultiplier($metal, 24.0) !== null;

        // reference_price_supported is Class B membership — capability-driven,
        // never hardcoded as ['platinum', 'copper']. Reading tier2Metals()
        // keeps a single source of truth.
        $referencePriceSupported = in_array($metal, MetalRegistry::tier2Metals(), true);

        return new MetalDescriptor(
            identity_class: MetalRegistry::identityClass($metal),
            pricing_class: MetalRegistry::pricingClass($metal),
            purity_selector_mode: MetalRegistry::puritySelectorMode($metal),
            purity_label: MetalRegistry::purityLabel($metal),
            purity_is_accounting_truth: $isAccounting,
            fine_weight_supported: $fineWeightSupported,
            reference_price_supported: $referencePriceSupported,
            active_purity_profiles: self::activePurityProfiles($shopId, $metal),
        );
    }

    /**
     * Active purity values for this metal in this shop.
     *
     * - Accounting metals (gold, silver): query shop_metal_purity_profiles
     *   directly with explicit shop_id and active flag. Returns the
     *   purity_value list sorted by sort_order.
     * - Specification metals (platinum): operator-facing hallmark grades.
     *   Returns a constant list — there is no per-shop profile table for
     *   spec metals (they are piece-priced, no rate path).
     * - Manual-grade metals (copper): empty list (no purity field at all).
     *
     * @return array<int, float|int>
     */
    private static function activePurityProfiles(int $shopId, string $metal): array
    {
        if (MetalRegistry::purityIsAccountingTruth($metal)) {
            if (! Schema::hasTable('shop_metal_purity_profiles')) {
                return [];
            }

            // Direct query — never goes through ShopPricingService (class-leak
            // protection: the registry must not import the rate engine).
            // Boolean comparison uses raw SQL to match the project-wide
            // PostgreSQL boolean pattern (CONSTITUTION §2 Pattern F4).
            $rows = DB::table('shop_metal_purity_profiles')
                ->where('shop_id', $shopId)
                ->where('metal_type', $metal)
                ->whereRaw('is_active IS TRUE')
                ->orderBy('sort_order')
                ->orderByDesc('purity_value')
                ->pluck('purity_value')
                ->all();

            return array_values(array_map(
                static fn ($v) => self::normalizePurity((float) $v),
                $rows
            ));
        }

        if (MetalRegistry::purityIsSpecification($metal)) {
            // Platinum hallmark grades — operator-facing spec values.
            // These never drive price; they are display-only.
            return match ($metal) {
                'platinum' => [95, 90],
                default    => [],
            };
        }

        // Manual-grade (copper) — no purity field, empty profile list.
        return [];
    }

    /**
     * Format purity to a clean numeric value — integer when whole, else
     * trimmed float. Keeps the JSON payload tidy (22 instead of "22.000").
     */
    private static function normalizePurity(float $value): float|int
    {
        if (floor($value) === $value) {
            return (int) $value;
        }

        return (float) rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
