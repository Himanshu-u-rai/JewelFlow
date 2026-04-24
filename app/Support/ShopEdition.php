<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Models\User;

/**
 * Central helper for edition checks and mutations.
 *
 * Post editions-refactor (Phase 2), editions are a SET, not a single
 * value — a shop can be retailer + dhiran, manufacturer only, dhiran
 * only, etc. The legacy type() / isRetailer() / isManufacturer() calls
 * still work but now delegate to the shop_editions pivot.
 *
 * Usage:
 *   ShopEdition::isRetailer()            → true if auth shop has retailer edition
 *   ShopEdition::activeFor($shop)        → ['retailer', 'dhiran']
 *   ShopEdition::grantTo($shop, 'dhiran', $admin)
 *   ShopEdition::revokeFrom($shop, 'dhiran', $admin, 'customer request')
 */
class ShopEdition
{
    public const RETAILER     = 'retailer';
    public const MANUFACTURER = 'manufacturer';
    public const DHIRAN       = 'dhiran';

    public const ALL = [self::RETAILER, self::MANUFACTURER, self::DHIRAN];

    /**
     * Primary edition of the current auth user's shop, for legacy call
     * sites that expect a single value. Prefers retailer/manufacturer
     * over dhiran since those are the "core" shop types.
     */
    public static function type(): ?string
    {
        $shop = auth()->user()?->shop;
        if (! $shop) {
            return null;
        }

        $editions = $shop->editionList();
        foreach ([self::RETAILER, self::MANUFACTURER, self::DHIRAN] as $preferred) {
            if (in_array($preferred, $editions, true)) {
                return $preferred;
            }
        }

        return null;
    }

    public static function isRetailer(): bool
    {
        return auth()->user()?->shop?->hasEdition(self::RETAILER) ?? false;
    }

    public static function isManufacturer(): bool
    {
        return auth()->user()?->shop?->hasEdition(self::MANUFACTURER) ?? false;
    }

    public static function hasDhiran(): bool
    {
        return auth()->user()?->shop?->hasEdition(self::DHIRAN) ?? false;
    }

    /**
     * Active editions for a specific shop (commands, jobs, admin).
     *
     * @return array<int, string>
     */
    public static function activeFor(Shop $shop): array
    {
        return $shop->editionList();
    }

    /**
     * Primary edition for a specific shop. Kept for call sites that
     * expected the legacy single-value API.
     */
    public static function for(Shop $shop): string
    {
        $editions = $shop->editionList();
        foreach ([self::RETAILER, self::MANUFACTURER, self::DHIRAN] as $preferred) {
            if (in_array($preferred, $editions, true)) {
                return $preferred;
            }
        }

        return self::MANUFACTURER;
    }

    public static function isRetailerShop(Shop $shop): bool
    {
        return $shop->hasEdition(self::RETAILER);
    }

    public static function isManufacturerShop(Shop $shop): bool
    {
        return $shop->hasEdition(self::MANUFACTURER);
    }

    /**
     * Grant an edition to a shop. Idempotent — a prior revoked row is
     * reactivated so audit history is preserved. Caller should be the
     * admin performing the action (or null for system grants).
     */
    public static function grantTo(Shop $shop, string $edition, ?User $actor = null): ShopEditionAssignment
    {
        static::assertValid($edition);

        return ShopEditionAssignment::updateOrCreate(
            ['shop_id' => $shop->id, 'edition' => $edition],
            [
                'activated_at'        => now(),
                'activated_by'        => $actor?->id,
                'deactivated_at'      => null,
                'deactivated_by'      => null,
                'deactivation_reason' => null,
            ]
        );
    }

    /**
     * Soft-revoke an edition. Row is retained with deactivated_* fields
     * populated so the grant can be reinstated and the audit trail
     * survives. Returns true if a row was revoked, false if the shop
     * didn't have that edition active.
     */
    public static function revokeFrom(Shop $shop, string $edition, ?User $actor = null, ?string $reason = null): bool
    {
        static::assertValid($edition);

        $updated = ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('edition', $edition)
            ->whereNull('deactivated_at')
            ->update([
                'deactivated_at'      => now(),
                'deactivated_by'      => $actor?->id,
                'deactivation_reason' => $reason,
            ]);

        return $updated > 0;
    }

    private static function assertValid(string $edition): void
    {
        if (! in_array($edition, self::ALL, true)) {
            throw new \InvalidArgumentException("Unknown shop edition: {$edition}");
        }
    }
}
