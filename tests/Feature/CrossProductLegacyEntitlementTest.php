<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformProduct;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Services\SubscriptionPaymentService;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P0 OPUS-AUDIT CORRECTIONS — group 7: cross-product legacy entitlement.
 *
 * `read_only` on a SUBSCRIPTION row means two irreconcilable things:
 *
 *   • LEGACY: the old expiry fork wrote it when a term ran out. It is a LAPSE.
 *     Nothing about it is paid-for or live.
 *   • ADMINISTRATIVE: a JewelFlows admin set it deliberately to leave a shop
 *     browsable-but-not-writable. That access is real and must be preserved.
 *
 * Two readers treat BOTH as live entitlement:
 *   ShopEdition::hasOtherActiveSource()  — keeps an edition alive after a lapse
 *   Shop::activeSubscriptionForProduct() — resolves the plan behind staffLimit()
 *
 * The lazy correction — delete the token from both arrays — is WRONG. It would
 * revoke the very edition an administrator preserved, and edition gating is
 * what makes read-only browsing work at all. The discriminator is not on the
 * subscription; it is Shop::suspensionIsAdministrative() (suspended_by), the
 * same proof-positive marker every other gate in this hotfix reads.
 *
 * So `read_only` entitles if and only if the shop carries that marker. Both
 * halves are proven below: the legacy row must not rescue a lapsed product, and
 * the administrative row must not lose the access an admin left in place.
 */
class CrossProductLegacyEntitlementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        config(['platform.enforce_subscriptions' => true]);
    }

    /**
     * A shop with NO auto-seeded editions (no shop_type, so Shop::created does
     * not fire the seed hook) — every edition below exists because a test put
     * it there.
     */
    private function bareShop(): Shop
    {
        $shop = new Shop();
        $shop->forceFill([
            'name'             => 'Cross Product Shop',
            'phone'            => fake()->unique()->numerify('9#########'),
            'owner_first_name' => 'Cross',
            'owner_last_name'  => 'Owner',
            'owner_mobile'     => fake()->unique()->numerify('9#########'),
            'is_active'        => true,
            'access_mode'      => 'active',
        ]);
        $shop->save();

        return $shop;
    }

    private function planForProduct(string $productCode, array $overrides = []): Plan
    {
        $prefix = match ($productCode) {
            'retail'        => 'retailer',
            'manufacturing' => 'manufacturer',
            default         => $productCode,
        };

        return Plan::create(array_merge([
            'code'                => $prefix . '_xp_' . fake()->unique()->numberBetween(1000, 999999),
            'name'                => ucfirst($productCode) . ' Cross Plan',
            'platform_product_id' => PlatformProduct::where('code', $productCode)->value('id'),
            'price_monthly'       => 1499,
            'price_yearly'        => 14999,
            'trial_days'          => 0,
            'grace_days'          => 14,
            'is_active'           => true,
            'features'            => ['staff_limit' => 7],
        ], $overrides));
    }

    private function makeSubscription(Shop $shop, Plan $plan, string $status, array $overrides = []): ShopSubscription
    {
        return ShopSubscription::create(array_merge([
            'shop_id'             => $shop->id,
            'plan_id'             => $plan->id,
            'status'              => $status,
            'starts_at'           => now()->subMonths(2)->toDateString(),
            'ends_at'             => now()->subMonth()->toDateString(),
            'grace_ends_at'       => now()->subDays(15)->toDateString(),
            'billing_cycle'       => 'monthly',
            'price_paid'          => 1499,
            'razorpay_payment_id' => 'pay_' . fake()->unique()->bothify('??########'),
            'razorpay_order_id'   => 'order_' . fake()->unique()->bothify('??########'),
        ], $overrides));
    }

    private function grantViaSubscription(ShopSubscription $sub): void
    {
        app(SubscriptionPaymentService::class)->grantEditionForSubscription($sub, $sub->plan);
    }

    private function activeEdition(Shop $shop, string $edition): ?ShopEditionAssignment
    {
        return ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('edition', $edition)
            ->whereNull('deactivated_at')
            ->first();
    }

    /** The exact JF-0001 shape: read_only with nobody's fingerprints on it. */
    private function unattributedLegacy(Shop $shop): void
    {
        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => false,
            'suspended_at'      => now()->subDays(20),
            'suspension_reason' => null,
            'suspended_by'      => null,
            'suspended_until'   => null,
        ])->save();
    }

    private function administrativeReadOnly(Shop $shop, PlatformAdmin $admin): void
    {
        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => true,
            'suspended_at'      => now()->subDays(2),
            'suspension_reason' => 'Compliance review by platform admin',
            'suspended_by'      => $admin->id,
            'suspended_until'   => null,
        ])->save();
    }

    // ════════════════════════════════════════════════════════════════════════
    // The LEGACY half — a lapse must not masquerade as entitlement
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Two products. Retail genuinely lapses. Manufacturing carries a LEGACY
     * read_only row and the shop has no administrative marker at all.
     *
     * The legacy row must not count as another live source, so the lapsed
     * product's edition is revoked as it would be on a single-product shop.
     */
    public function test_legacy_read_only_on_another_product_does_not_rescue_a_lapsed_edition(): void
    {
        $shop = $this->bareShop();

        $retailPlan = $this->planForProduct('retail');
        $mfgPlan    = $this->planForProduct('manufacturing');

        $retailSub = $this->makeSubscription($shop, $retailPlan, 'expired');
        $mfgSub    = $this->makeSubscription($shop, $mfgPlan, 'read_only');
        $this->grantViaSubscription($retailSub);
        $this->grantViaSubscription($mfgSub);

        $this->unattributedLegacy($shop);
        $shop->refresh();

        $this->assertFalse(
            $shop->suspensionIsAdministrative(),
            'Fixture must carry NO administrative marker — that is the whole point.'
        );

        $this->assertFalse(
            ShopEdition::hasOtherActiveSource($shop, 'retailer', $retailSub->id),
            'A legacy read_only row is a LAPSE, not another live source.'
        );

        $revoked = ShopEdition::revokeFromLapsedSubscription($shop, 'retailer', $retailSub->id);

        $this->assertTrue($revoked, 'The lapsed product must lose its edition.');
        $this->assertNull($this->activeEdition($shop, 'retailer'));
    }

    /**
     * The SAME-product variant, which is the sharper one: an expired row and a
     * legacy read_only row for one product. The read_only row used to keep the
     * edition alive forever, so a lapsed shop stayed writable-by-edition.
     */
    public function test_legacy_read_only_on_the_same_product_does_not_keep_the_edition_alive(): void
    {
        $shop = $this->bareShop();
        $plan = $this->planForProduct('retail');

        $expired  = $this->makeSubscription($shop, $plan, 'expired');
        $this->makeSubscription($shop, $plan, 'read_only');
        $this->grantViaSubscription($expired);

        $this->unattributedLegacy($shop);
        $shop->refresh();

        $this->assertTrue(ShopEdition::revokeFromLapsedSubscription($shop, 'retailer', $expired->id));
        $this->assertNull($this->activeEdition($shop, 'retailer'));
    }

    /** The staff-seat reader must not resolve a plan through a legacy row either. */
    public function test_legacy_read_only_does_not_resolve_as_the_active_product_subscription(): void
    {
        $shop = $this->bareShop();
        $plan = $this->planForProduct('retail', ['features' => ['staff_limit' => 25]]);
        $this->makeSubscription($shop, $plan, 'read_only');

        $this->unattributedLegacy($shop);
        $shop->refresh();

        $this->assertNull(
            $shop->activeSubscriptionForProduct('retail'),
            'A legacy read_only row is not an active product subscription.'
        );
    }

    /** An admin_grant edition still survives a lapse — unrelated behaviour intact. */
    public function test_an_admin_granted_edition_still_survives_a_lapse(): void
    {
        $shop  = $this->bareShop();
        $admin = $this->createPlatformAdmin();
        $plan  = $this->planForProduct('retail');
        $sub   = $this->makeSubscription($shop, $plan, 'expired');

        ShopEdition::grantTo($shop, 'retailer', null, ShopEditionAssignment::SOURCE_ADMIN_GRANT);
        $this->unattributedLegacy($shop);
        $shop->refresh();

        $this->assertFalse(ShopEdition::revokeFromLapsedSubscription($shop, 'retailer', $sub->id));
        $this->assertNotNull($this->activeEdition($shop, 'retailer'));
    }

    // ════════════════════════════════════════════════════════════════════════
    // The ADMINISTRATIVE half — the companion case the correction must not break
    // ════════════════════════════════════════════════════════════════════════

    /**
     * COMPANION CASE. An administrator put this shop on read-only, which on the
     * subscription row reads exactly like the legacy state. Deleting `read_only`
     * outright would revoke the edition — and edition gating is what makes
     * read-only browsing work — so the admin's deliberate "browsable, not
     * writable" decision would collapse into no access at all.
     */
    public function test_administrative_read_only_still_backs_its_edition(): void
    {
        $shop  = $this->bareShop();
        $admin = $this->createPlatformAdmin();
        $plan  = $this->planForProduct('retail');

        $expired  = $this->makeSubscription($shop, $plan, 'expired');
        $adminSub = $this->makeSubscription($shop, $plan, 'read_only');
        $this->grantViaSubscription($adminSub);

        $this->administrativeReadOnly($shop, $admin);
        $shop->refresh();

        $this->assertTrue($shop->suspensionIsAdministrative());

        $this->assertTrue(
            ShopEdition::hasOtherActiveSource($shop, 'retailer', $expired->id),
            'An administrative read_only hold is real, deliberate access.'
        );

        $this->assertFalse(
            ShopEdition::revokeFromLapsedSubscription($shop, 'retailer', $expired->id),
            'A lapse elsewhere must never revoke an administrator-held edition.'
        );
        $this->assertNotNull($this->activeEdition($shop, 'retailer'));
    }

    /** …and the staff-seat reader keeps resolving it, so seats do not silently drop. */
    public function test_administrative_read_only_still_resolves_as_the_product_subscription(): void
    {
        $shop  = $this->bareShop();
        $admin = $this->createPlatformAdmin();
        $plan  = $this->planForProduct('retail', ['features' => ['staff_limit' => 25]]);
        $this->makeSubscription($shop, $plan, 'read_only');

        $this->administrativeReadOnly($shop, $admin);
        $shop->refresh();

        $sub = $shop->activeSubscriptionForProduct('retail');

        $this->assertNotNull($sub, 'An administrator hold must not erase the resolved plan.');
        $this->assertSame(25, $shop->staffLimit());
    }

    /** A genuinely live product is unaffected by either half of the rule. */
    public function test_a_live_product_still_rescues_its_edition(): void
    {
        $shop = $this->bareShop();

        $retailPlan = $this->planForProduct('retail');
        $expired    = $this->makeSubscription($shop, $retailPlan, 'expired');
        $live       = $this->makeSubscription($shop, $retailPlan, 'active', [
            'ends_at'       => now()->addMonth()->toDateString(),
            'grace_ends_at' => now()->addMonth()->addDays(14)->toDateString(),
        ]);
        $this->grantViaSubscription($live);

        $this->assertTrue(ShopEdition::hasOtherActiveSource($shop, 'retailer', $expired->id));
        $this->assertFalse(ShopEdition::revokeFromLapsedSubscription($shop, 'retailer', $expired->id));
        $this->assertNotNull($this->activeEdition($shop, 'retailer'));
        $this->assertNotNull($shop->activeSubscriptionForProduct('retail'));
    }
}
