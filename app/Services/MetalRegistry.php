<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * MetalRegistry — Constitutional Article XV authority for material support.
 *
 * Every code path that mentions a metal must consult MetalRegistry. The
 * only legitimate locations where metal literals may appear are:
 *   (1) inside this class itself
 *   (2) inside migration files defining historical CHECK constraints
 *   (3) inside stone_types / reference data seeders (Phase 2A+)
 *
 * Three tiers govern operational capability:
 *   - Tier 1 (FULL):    gold, silver — every flow without restriction
 *   - Tier 2 (LIMITED): platinum, copper — inventory + invoicing only,
 *                       no dhiran, no exchange payment, no auto-reprice,
 *                       no live-rate auto-fetch
 *   - Tier 3 (BLOCKED): everything else — rejected at three layers
 *                       (controller validator, service guard, DB CHECK)
 *
 * Per-shop opt-in is governed by `shop_enabled_metals`. Tier 1 metals
 * are auto-enabled on shop creation; Tier 2 metals require explicit
 * operator opt-in via Settings → Materials.
 *
 * @see CONSTITUTION.md Article XV — MetalRegistry Authority
 * @see CONSTITUTION.md Article XIII — Material Tier Doctrine
 * @see config/materials.php
 */
final class MetalRegistry
{
    public const TIER_1 = 1;
    public const TIER_2 = 2;
    public const TIER_3 = 3;

    // ─────────────────────────────────────────────────────────────────────
    // Mobile registry snapshot version (M1 — registry endpoint contract).
    //
    // Bump this constant whenever the SHAPE of the materials snapshot
    // changes (new capability fields, semantics change for an existing
    // field, etc.). Mobile clients read this to know when to refresh
    // their cached registry; the version is the single-file change point.
    //
    // Convention: 'YYYY-MM-DD.N' where N is the same-day bump counter.
    // ─────────────────────────────────────────────────────────────────────
    public const REGISTRY_VERSION = '2026-05-28.1';

    // ─────────────────────────────────────────────────────────────────────
    // UX capability map (Stage 1)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Operator UX default for how the item price should be entered.
     * Gold/silver are typically rate-derived; platinum/copper are
     * typically piece-priced.
     *
     * @return 'rate_derived'|'piece_price'
     */
    public static function uxItemCreationDefault(string $metal): string
    {
        $normalized = self::normalize($metal);
        self::assertSupported($normalized);

        return match ($normalized) {
            'gold', 'silver' => 'rate_derived',
            'platinum', 'copper' => 'piece_price',
            default => throw new \InvalidArgumentException(
                "Unsupported metal '{$normalized}' for UX item creation default."
            ),
        };
    }

    /**
     * Whether the rates dashboard should show a top-level rate panel for
     * this metal.
     */
    public static function uxRatesDashboardVisible(string $metal): bool
    {
        $normalized = self::normalize($metal);
        self::assertSupported($normalized);

        return match ($normalized) {
            'gold', 'silver' => true,
            'platinum', 'copper' => false,
            default => throw new \InvalidArgumentException(
                "Unsupported metal '{$normalized}' for UX rates dashboard visibility."
            ),
        };
    }

    /**
     * Whether the metal should appear in the item picker for a given shop.
     * Tier 1 metals are always visible; Tier 2 metals require shop opt-in.
     */
    public static function uxItemPickerVisible(string $metal, int $shopId): bool
    {
        $normalized = self::normalize($metal);
        self::assertSupported($normalized);

        if (! in_array($normalized, self::allSupportedMetals(), true)) {
            // Defensive: should be redundant with assertSupported.
            throw new \InvalidArgumentException(
                "Unsupported metal '{$normalized}' for UX item picker visibility."
            );
        }

        $tier = self::tierFor($normalized);
        if ($tier === self::TIER_1) {
            return true;
        }

        return self::isEnabledForShop($shopId, $normalized);
    }

    /**
     * Whether the customer rate UI should display this metal.
     */
    public static function uxCustomerRateDisplayable(string $metal): bool
    {
        return self::uxRatesDashboardVisible($metal);
    }

    /**
     * Whether this metal is considered a primary vault line (gold/silver
     * expanded vs other metals collapsed).
     */
    public static function uxVaultPrimary(string $metal): bool
    {
        return self::uxRatesDashboardVisible($metal);
    }

    /**
     * UX explicit reconciliation default; mirrors the constitutional
     * reconciliation capability.
     */
    public static function uxGramReconciliationDefault(string $metal): bool
    {
        $normalized = self::normalize($metal);
        self::assertSupported($normalized);

        return self::isReconciliationEligible($normalized);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Material identity systems (Identity Alignment — P1)
    //
    // "Purity" is not one concept. Each metal belongs to exactly one identity
    // class that determines whether purity is accounting truth, a hallmark
    // specification, or absent. See docs/runbooks/material-identity-audit.md.
    //
    //   A — purity_accounting : gold, silver  (purity = fine-weight multiplier)
    //   B — purity_spec        : platinum      (purity = hallmark spec; piece-priced)
    //   D — manual_grade       : copper        (no purity; piece-priced)
    //
    // (Stones are class C — attribute_value — and are NOT metals; their class
    //  is asserted at the stone layer, never here.)
    // ─────────────────────────────────────────────────────────────────────

    public const IDENTITY_PURITY_ACCOUNTING = 'purity_accounting';
    public const IDENTITY_PURITY_SPEC = 'purity_spec';
    public const IDENTITY_MANUAL_GRADE = 'manual_grade';
    public const IDENTITY_ATTRIBUTE_VALUE = 'attribute_value'; // stones — not a metal

    /**
     * The single source of truth for a metal's identity class. Every
     * identity-derived capability below reads from this.
     *
     * @return self::IDENTITY_*
     */
    public static function identityClass(string $metal): string
    {
        $normalized = self::normalize($metal);
        self::assertSupported($normalized);

        return match ($normalized) {
            'gold', 'silver' => self::IDENTITY_PURITY_ACCOUNTING,
            'platinum'       => self::IDENTITY_PURITY_SPEC,
            'copper'         => self::IDENTITY_MANUAL_GRADE,
            default => throw new \InvalidArgumentException(
                "No identity class defined for metal '{$normalized}'."
            ),
        };
    }

    /**
     * Purity is a fine-weight multiplier (accounting truth). True ONLY for
     * gold/silver. This is the gate the fine-weight authority consults so a
     * non-accounting metal's purity can never become a multiplier.
     */
    public static function purityIsAccountingTruth(string $metal): bool
    {
        return self::identityClass($metal) === self::IDENTITY_PURITY_ACCOUNTING;
    }

    /**
     * Purity is a hallmark/quality specification (displayed, not calculated).
     * True ONLY for platinum.
     */
    public static function purityIsSpecification(string $metal): bool
    {
        return self::identityClass($metal) === self::IDENTITY_PURITY_SPEC;
    }

    /**
     * Whether a hallmark/quality stamp is meaningful for this metal
     * (gold HUID, silver fineness, platinum Pt950). Copper has none.
     */
    public static function hallmarkRelevant(string $metal): bool
    {
        return in_array(
            self::identityClass($metal),
            [self::IDENTITY_PURITY_ACCOUNTING, self::IDENTITY_PURITY_SPEC],
            true
        );
    }

    /**
     * How the item-creation form should present the purity field.
     *   mandatory   — gold/silver (required, drives price)
     *   lightweight — platinum (optional hallmark-grade spec, never drives price)
     *   hidden      — copper (no purity field)
     *
     * @return 'mandatory'|'lightweight'|'hidden'
     */
    public static function puritySelectorMode(string $metal): string
    {
        return match (self::identityClass($metal)) {
            self::IDENTITY_PURITY_ACCOUNTING => 'mandatory',
            self::IDENTITY_PURITY_SPEC       => 'lightweight',
            default                          => 'hidden',
        };
    }

    /**
     * Operator-facing label for the purity field, scale-correct per metal.
     * Empty string when the metal has no purity field.
     */
    public static function purityLabel(string $metal): string
    {
        $normalized = self::normalize($metal);
        self::assertSupported($normalized);

        return match ($normalized) {
            'gold'     => 'Karat (K)',
            'silver'   => 'Fineness',
            'platinum' => 'Hallmark grade',
            default    => '',
        };
    }

    /**
     * The metals whose purity is accounting truth (i.e. participate in
     * fine-weight / vault / gram reconciliation). Capability-driven so callers
     * never hardcode ['gold','silver'].
     *
     * @return list<string>
     */
    public static function accountingTruthMetals(): array
    {
        return array_values(array_filter(
            self::allSupportedMetals(),
            fn (string $metal) => self::purityIsAccountingTruth($metal)
        ));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fine-weight authority (Identity Alignment — P2)
    //
    // THE single sanctioned way to turn purity into a fine-weight multiplier.
    // Returns null for any metal whose purity is NOT accounting truth, so a
    // platinum/copper purity value can never silently become a multiplier.
    //
    // Rule for all contributors: fine weight comes ONLY from here. Never write
    // inline `purity / 24` or `purity / 1000` in new code.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Fine-weight multiplier for a metal's stored purity, or null when purity
     * is not accounting truth for this metal (platinum/copper).
     *
     * Scale is keyed on metal_type — NOT guessed from the purity magnitude:
     *   gold   → karat scale  (purity / 24)
     *   silver → millesimal   (purity / 1000)
     */
    public static function fineWeightMultiplier(string $metal, float $purity): ?float
    {
        $normalized = self::normalize($metal);
        self::assertSupported($normalized);

        if (! self::purityIsAccountingTruth($normalized)) {
            return null;
        }

        return match ($normalized) {
            'gold'   => $purity / 24.0,
            'silver' => $purity / 1000.0,
            default  => null,
        };
    }

    /**
     * Fine weight = net weight × multiplier, rounded to 6dp. Null when the
     * metal's purity is not accounting truth (caller must handle — never
     * fabricate a fine weight for a non-accounting metal).
     */
    public static function fineWeight(string $metal, float $netWeight, float $purity): ?float
    {
        $multiplier = self::fineWeightMultiplier($metal, $purity);

        return $multiplier === null ? null : round($netWeight * $multiplier, 6);
    }


    /**
     * Per-process cache. Reset between requests; safe across requests
     * because config and shop_enabled_metals are read each time.
     */
    private static array $shopEnabledCache = [];

    // ─────────────────────────────────────────────────────────────────────
    // System-level tier introspection
    // ─────────────────────────────────────────────────────────────────────

    /**
     * All metals the system understands (Tier 1 ∪ Tier 2).
     */
    public static function allSupportedMetals(): array
    {
        return array_values(array_merge(self::tier1Metals(), self::tier2Metals()));
    }

    public static function tier1Metals(): array
    {
        return (array) config('materials.tier_1', ['gold', 'silver']);
    }

    public static function tier2Metals(): array
    {
        return (array) config('materials.tier_2', []);
    }

    /**
     * Tier classification for a given metal.
     * Returns TIER_3 for any metal not in tier_1 or tier_2.
     */
    public static function tierFor(string $metalType): int
    {
        $normalized = self::normalize($metalType);
        if (in_array($normalized, self::tier1Metals(), true)) {
            return self::TIER_1;
        }
        if (in_array($normalized, self::tier2Metals(), true)) {
            return self::TIER_2;
        }
        return self::TIER_3;
    }

    /**
     * Is this metal known to the system at all? Tier 1 or Tier 2 = yes.
     */
    public static function isSupported(string $metalType): bool
    {
        return self::tierFor($metalType) !== self::TIER_3;
    }

    /**
     * Constitutional assertion: throws if the metal is Tier 3.
     * Use at service-layer boundaries to fail loudly on unsupported input.
     */
    public static function assertSupported(string $metalType): void
    {
        if (! self::isSupported($metalType)) {
            $normalized = self::normalize($metalType);
            $supported  = implode(', ', self::allSupportedMetals());
            throw new LogicException(
                "Metal '{$normalized}' is not supported. Supported metals: {$supported}."
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Capability checks (which Tier 2 restrictions apply)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Live-rate auto-fetch (FetchLiveMetalRatesJob) is Tier 1 only.
     * Platinum/copper rates are manually entered, never API-fetched.
     */
    public static function isLiveRateEligible(string $metalType): bool
    {
        return self::tierFor($metalType) === self::TIER_1;
    }

    /**
     * Retailer auto-reprice job (RepriceRetailerInventoryJob) is Tier 1 only.
     * Tier 2 items hold their manually-entered selling_price; the reprice
     * job skips them.
     */
    public static function isAutoRepricedEligible(string $metalType): bool
    {
        return self::tierFor($metalType) === self::TIER_1;
    }

    /**
     * Dhiran (gold-loan collateral) is Tier 1 only. Tier 2 metals cannot
     * be used as loan collateral.
     */
    public static function isDhiranEligible(string $metalType): bool
    {
        return self::tierFor($metalType) === self::TIER_1;
    }

    /**
     * Exchange payment (old-metal trade-in at POS via old_gold/old_silver
     * payment modes) is Tier 1 only. Tier 2 trade-ins require a separate
     * cash transaction; cannot reduce invoice subtotal via this path.
     */
    public static function isExchangePaymentEligible(string $metalType): bool
    {
        return self::tierFor($metalType) === self::TIER_1;
    }

    /**
     * Reporting visibility: Tier 1 + Tier 2. Reports break out both tiers
     * separately. Tier 3 metals never appear because they cannot be
     * recorded.
     */
    public static function isReportingVisible(string $metalType): bool
    {
        return self::tierFor($metalType) !== self::TIER_3;
    }

    /**
     * Reconciliation eligibility: Tier 1 + Tier 2. Vault/karigar/closing
     * reconciliations report per-metal and include all supported metals.
     */
    public static function isReconciliationEligible(string $metalType): bool
    {
        return self::tierFor($metalType) !== self::TIER_3;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Per-shop opt-in (reads shop_enabled_metals)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Metals this shop has explicitly enabled. Tier 1 metals are
     * auto-enabled by the Phase 1 seed migration; Tier 2 metals require
     * the operator to opt in via Settings → Materials.
     *
     * Falls back to Tier 1 set if shop_enabled_metals table is empty
     * (defensive: a freshly-created shop with no enabled-metals rows
     * still functions for gold/silver).
     *
     * @return string[]
     */
    public static function enabledMetalsForShop(int $shopId): array
    {
        if (isset(self::$shopEnabledCache[$shopId])) {
            return self::$shopEnabledCache[$shopId];
        }

        // PostgreSQL rejects PHP true→1 cast on boolean columns. Use
        // explicit SQL literal via whereRaw, matching the project-wide
        // pattern (DetectStuckStates, ReconcileVaultBalances, Vendor::scopeActive).
        $rows = DB::table('shop_enabled_metals')
            ->where('shop_id', $shopId)
            ->whereRaw('enabled IS TRUE')
            ->pluck('metal_type')
            ->all();

        if (empty($rows)) {
            // Defensive fallback: never leave a shop with zero supported metals.
            // The seed migration should have populated rows for every shop,
            // but if a shop predates the seed for any reason, give it Tier 1.
            $rows = self::tier1Metals();
        }

        return self::$shopEnabledCache[$shopId] = array_values(array_unique($rows));
    }

    /**
     * Is `$metalType` enabled for this specific shop? Considers both the
     * system-level tier list AND the shop's explicit opt-in.
     */
    public static function isEnabledForShop(int $shopId, string $metalType): bool
    {
        $normalized = self::normalize($metalType);
        return in_array($normalized, self::enabledMetalsForShop($shopId), true);
    }

    /**
     * Service-layer assertion for per-shop scope. Throws if the metal is
     * not enabled for this shop. Use at any point where a shop-scoped
     * operation accepts a metal_type.
     */
    public static function assertEnabledForShop(int $shopId, string $metalType): void
    {
        if (! self::isEnabledForShop($shopId, $metalType)) {
            $normalized = self::normalize($metalType);
            $enabled = implode(', ', self::enabledMetalsForShop($shopId));
            throw new LogicException(
                "Metal '{$normalized}' is not enabled for shop {$shopId}. "
                . "Enabled metals: {$enabled}. "
                . "Enable from Settings → Materials if this is a Tier 2 metal."
            );
        }
    }

    /**
     * Reset the per-shop cache. Call after enabling/disabling a metal
     * via Settings.
     */
    public static function clearShopCache(?int $shopId = null): void
    {
        if ($shopId === null) {
            self::$shopEnabledCache = [];
            return;
        }
        unset(self::$shopEnabledCache[$shopId]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Normalization
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Canonical lowercase form. Throws if input is empty/null.
     * Does NOT validate tier — use isSupported() / assertSupported() for that.
     */
    public static function normalize(string $metalType): string
    {
        $normalized = strtolower(trim($metalType));
        if ($normalized === '') {
            throw new LogicException('Metal type cannot be empty.');
        }
        return $normalized;
    }

    /**
     * For Laravel validator: `Rule::in(MetalRegistry::validationListForShop($shopId))`.
     * Returns the set of metals a controller may accept for this shop.
     */
    public static function validationListForShop(int $shopId): array
    {
        return self::enabledMetalsForShop($shopId);
    }

    /**
     * For Laravel validator at system-wide scope (no shop context yet).
     * Returns all supported metals across the system.
     */
    public static function validationListSystemWide(): array
    {
        return self::allSupportedMetals();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pricing classes (M1 — mobile registry contract).
    //
    // Three pricing classes describe how a material's price is established:
    //   A — daily rate × fine weight (gold, silver — accounting truth metals)
    //   B — reference price memo, piece-priced at POS (platinum, copper — Tier 2)
    //   C — stones — NOT a metal; asserted at the stone layer, never here.
    //
    // Stones intentionally go through a separate path: they have no metal
    // pricing class, and passing 'stone' / any stone identifier here MUST
    // throw. The registry endpoint surfaces stones as a separate descriptor.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Pricing class for a metal. Throws for anything that is not a known
     * metal (including stones, which have their own path).
     *
     * @return 'A'|'B'
     */
    public static function pricingClass(string $metal): string
    {
        $normalized = self::normalize($metal);
        self::assertSupported($normalized);

        if (in_array($normalized, self::accountingTruthMetals(), true)) {
            return 'A';
        }

        if (in_array($normalized, self::tier2Metals(), true)) {
            return 'B';
        }

        throw new LogicException(
            "Metal '{$normalized}' has no pricing class. Stones are class C and go through "
            . 'a separate path; they must never be passed to MetalRegistry::pricingClass().'
        );
    }

    /**
     * Accessor for the registry snapshot version constant. Mobile clients
     * use this to detect when their cached materials snapshot is stale.
     */
    public static function registryVersion(): string
    {
        return self::REGISTRY_VERSION;
    }
}
