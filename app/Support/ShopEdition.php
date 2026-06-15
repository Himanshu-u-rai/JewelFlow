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

    // Future product editions. These map 1:1 to platform product codes and are
    // accepted by the DB CHECK constraint, but are not yet surfaced in the
    // owner-facing add/remove UI (which stays on the three core editions).
    public const CRM            = 'crm';
    public const ANALYTICS      = 'analytics';
    public const MOBILE_PREMIUM = 'mobile_premium';

    /**
     * The core editions the owner-facing UI exposes today. Kept as-is so
     * /settings/services validation and the admin grant dropdown are unchanged.
     */
    public const ALL = [self::RETAILER, self::MANUFACTURER, self::DHIRAN];

    /**
     * Every edition string the system can grant — including the future product
     * editions allowed by the extended shop_editions CHECK constraint. Used to
     * validate subscription-sourced grants so a new product never throws.
     */
    public const GRANTABLE = [
        self::RETAILER,
        self::MANUFACTURER,
        self::DHIRAN,
        self::CRM,
        self::ANALYTICS,
        self::MOBILE_PREMIUM,
    ];

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
     *
     * This is the ADMIN-GRANT path: source defaults to 'admin_grant' so a
     * grant made here survives any subscription lapse. Subscription-driven
     * grants must use grantFromSubscription() instead.
     */
    public static function grantTo(
        Shop $shop,
        string $edition,
        ?User $actor = null,
        string $source = ShopEditionAssignment::SOURCE_ADMIN_GRANT,
        ?int $productSubscriptionId = null,
    ): ShopEditionAssignment {
        static::assertValid($edition);

        return ShopEditionAssignment::updateOrCreate(
            ['shop_id' => $shop->id, 'edition' => $edition],
            [
                'source'                  => $source,
                'product_subscription_id' => $productSubscriptionId,
                'activated_at'            => now(),
                'activated_by'            => $actor?->id,
                'deactivated_at'          => null,
                'deactivated_by'          => null,
                'deactivation_reason'     => null,
            ]
        );
    }

    /**
     * Grant (or reactivate) an edition because a paid product subscription is
     * now active. Idempotent: the UNIQUE (shop_id, edition) plus updateOrCreate
     * means re-running this (e.g. renewal, duplicate webhook) never duplicates
     * a row — it just refreshes the backing subscription id and clears any
     * prior deactivation.
     *
     * Source-preservation rule: if the edition is ALREADY active via a stronger
     * source (admin_grant or seed — both lapse-immune), we keep that source and
     * only attach the subscription id. This stops a paid subscription from
     * silently downgrading a lapse-immune grant into a revocable one. Only when
     * the row is absent, deactivated, or already subscription-sourced does the
     * source become 'subscription'.
     */
    public static function grantFromSubscription(
        Shop $shop,
        string $edition,
        int $productSubscriptionId,
    ): ShopEditionAssignment {
        static::assertValid($edition);

        $existing = ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('edition', $edition)
            ->first();

        $keepStrongerSource = $existing
            && $existing->deactivated_at === null
            && in_array($existing->source, [
                ShopEditionAssignment::SOURCE_ADMIN_GRANT,
                ShopEditionAssignment::SOURCE_SEED,
            ], true);

        return ShopEditionAssignment::updateOrCreate(
            ['shop_id' => $shop->id, 'edition' => $edition],
            [
                'source'                  => $keepStrongerSource
                    ? $existing->source
                    : ShopEditionAssignment::SOURCE_SUBSCRIPTION,
                'product_subscription_id' => $productSubscriptionId,
                'activated_at'            => now(),
                'deactivated_at'          => null,
                'deactivated_by'          => null,
                'deactivation_reason'     => null,
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

    /**
     * Revoke an edition that a now-lapsed subscription was backing — but ONLY
     * when no OTHER active source still justifies it.
     *
     * An edition stays active while ANY active source backs it:
     *   - another active/trial/grace paid subscription for the same product, OR
     *   - an admin_grant / seed (which are NEVER auto-revoked by a lapse).
     *
     * If the only thing keeping the edition alive was the lapsed subscription,
     * the edition row is soft-revoked. Otherwise it is left untouched.
     *
     * Returns true if the edition was revoked, false if it was kept alive.
     */
    public static function revokeFromLapsedSubscription(
        Shop $shop,
        string $edition,
        int $lapsedSubscriptionId,
        ?string $reason = null,
    ): bool {
        static::assertValid($edition);

        $row = ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('edition', $edition)
            ->whereNull('deactivated_at')
            ->first();

        if (! $row) {
            return false; // not active anyway
        }

        // An admin_grant (or seed) edition is never auto-revoked by a lapse —
        // it represents access the shop holds independently of this payment.
        if (in_array($row->source, [
            ShopEditionAssignment::SOURCE_ADMIN_GRANT,
            ShopEditionAssignment::SOURCE_SEED,
        ], true)) {
            return false;
        }

        // If a different active source still backs this edition, keep it alive.
        if (static::hasOtherActiveSource($shop, $edition, $lapsedSubscriptionId)) {
            return false;
        }

        $row->update([
            'deactivated_at'      => now(),
            'deactivated_by'      => null,
            'deactivation_reason' => $reason ?? 'Subscription lapsed and no other active source backs this service.',
        ]);

        return true;
    }

    /**
     * Whether ANY active source (other than the given lapsed subscription)
     * still justifies the edition: an active admin_grant / seed on the edition
     * row, or another paid subscription for the same product still in a
     * writable state (active / trial / grace / read_only).
     */
    public static function hasOtherActiveSource(Shop $shop, string $edition, ?int $excludeSubscriptionId = null): bool
    {
        // 1) Is the live edition row itself an admin_grant / seed? Then it stands.
        $row = ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('edition', $edition)
            ->whereNull('deactivated_at')
            ->first();

        if ($row && in_array($row->source, [
            ShopEditionAssignment::SOURCE_ADMIN_GRANT,
            ShopEditionAssignment::SOURCE_SEED,
        ], true)) {
            return true;
        }

        // 2) Any OTHER subscription for the same product still entitling?
        //    Statuses that still grant access: active / trial / grace, and
        //    read_only (still a paid-but-overdue state, not a full lapse).
        $entitlingStatuses = ['active', 'trial', 'grace', 'read_only'];

        $query = \App\Models\Platform\ShopSubscription::query()
            ->where('shop_id', $shop->id)
            ->whereIn('status', $entitlingStatuses)
            ->with('plan.platformProduct');

        if ($excludeSubscriptionId !== null) {
            $query->where('id', '!=', $excludeSubscriptionId);
        }

        // grantsEdition() resolves product → edition string (with a code-prefix
        // fallback for ad-hoc / pre-backfill plans), so this stays correct
        // regardless of whether platform_product_id was set.
        foreach ($query->get() as $sub) {
            if ($sub->plan && $sub->plan->grantsEdition() === $edition) {
                return true;
            }
        }

        return false;
    }

    private static function assertValid(string $edition): void
    {
        if (! in_array($edition, self::GRANTABLE, true)) {
            throw new \InvalidArgumentException("Unknown shop edition: {$edition}");
        }
    }
}
