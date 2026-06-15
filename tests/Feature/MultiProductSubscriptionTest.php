<?php

namespace Tests\Feature;

use App\Console\Commands\CheckSubscriptionExpiry;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformProduct;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Models\User;
use App\Services\SubscriptionGateService;
use App\Services\SubscriptionPaymentService;
use App\Support\ShopEdition;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 2 — multi-product billing catalog + subscription→edition wiring.
 *
 * One shop = MANY product subscriptions. A paid subscription grants an edition
 * (source=subscription). A full lapse revokes the subscription-sourced edition
 * only when no other active source backs it; admin grants survive lapses.
 */
class MultiProductSubscriptionTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        config(['platform.enforce_subscriptions' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * A bare shop with NO seeded editions (created without a shop_type so the
     * Shop::created auto-seed hook does not fire). Lets each test assert exactly
     * which editions exist because of subscriptions/grants.
     */
    private function bareShop(): Shop
    {
        $shop = new Shop();
        $shop->forceFill([
            'name'             => 'Multi Product Shop',
            'phone'            => fake()->unique()->numerify('9#########'),
            'owner_first_name' => 'Multi',
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
        $productId = PlatformProduct::where('code', $productCode)->value('id');

        $prefix = match ($productCode) {
            'retail'        => 'retailer',
            'manufacturing' => 'manufacturer',
            default         => $productCode,
        };

        return Plan::create(array_merge([
            'code'                => $prefix . '_test_' . fake()->unique()->numberBetween(1000, 999999),
            'name'                => ucfirst($productCode) . ' Test Plan',
            'platform_product_id' => $productId,
            'price_monthly'       => 1499,
            'price_yearly'        => 14999,
            'trial_days'          => 0,
            'grace_days'          => 14,
            'downgrade_to_read_only_on_due' => true,
            'is_active'           => true,
            'features'            => ['staff_limit' => 5],
        ], $overrides));
    }

    private function makeSubscription(Shop $shop, Plan $plan, string $status = 'active', array $overrides = []): ShopSubscription
    {
        $admin = PlatformAdmin::where('role', 'super_admin')->first() ?? $this->createPlatformAdmin();

        return ShopSubscription::create(array_merge([
            'shop_id'             => $shop->id,
            'plan_id'             => $plan->id,
            'status'              => $status,
            'starts_at'           => now()->subDay()->toDateString(),
            'ends_at'             => now()->addMonth()->toDateString(),
            'grace_ends_at'       => now()->addMonth()->addDays(14)->toDateString(),
            'billing_cycle'       => 'monthly',
            'price_paid'          => 1499,
            'razorpay_payment_id' => 'pay_' . fake()->unique()->bothify('??########'),
            'razorpay_order_id'   => 'order_' . fake()->unique()->bothify('??########'),
            'updated_by_admin_id' => $admin->id,
        ], $overrides));
    }

    /** Grant an edition through the subscription wiring (as createSubscription would). */
    private function grantViaSubscription(Shop $shop, ShopSubscription $sub): void
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

    // ── product.code ↔ edition mapping ──────────────────────────────

    public function test_platform_products_seeded_with_correct_edition_mapping(): void
    {
        $this->assertSame('retailer',     PlatformProduct::editionStringFor('retail'));
        $this->assertSame('manufacturer', PlatformProduct::editionStringFor('manufacturing'));
        $this->assertSame('dhiran',       PlatformProduct::editionStringFor('dhiran'));
        $this->assertSame('crm',          PlatformProduct::editionStringFor('crm'));

        $this->assertSame('retail',        PlatformProduct::codeForEdition('retailer'));
        $this->assertSame('manufacturing', PlatformProduct::codeForEdition('manufacturer'));

        $this->assertTrue(PlatformProduct::where('code', 'retail')->value('is_active') === true
            || PlatformProduct::where('code', 'retail')->value('is_active') == 1);
        $this->assertFalse((bool) PlatformProduct::where('code', 'crm')->value('is_active'));
    }

    public function test_edition_check_allows_new_product_strings(): void
    {
        // The extended CHECK constraint must accept crm/analytics/mobile_premium.
        $shop = $this->bareShop();

        foreach (['crm', 'analytics', 'mobile_premium'] as $edition) {
            ShopEditionAssignment::create([
                'shop_id'      => $shop->id,
                'edition'      => $edition,
                'source'       => ShopEditionAssignment::SOURCE_ADMIN_GRANT,
                'activated_at' => now(),
            ]);
        }

        $this->assertDatabaseHas('shop_editions', ['shop_id' => $shop->id, 'edition' => 'crm']);
        $this->assertDatabaseHas('shop_editions', ['shop_id' => $shop->id, 'edition' => 'analytics']);
        $this->assertDatabaseHas('shop_editions', ['shop_id' => $shop->id, 'edition' => 'mobile_premium']);
    }

    // ── (A) Retail-only ─────────────────────────────────────────────

    public function test_retail_only_grants_retail_edition_writable_no_dhiran(): void
    {
        $shop = $this->bareShop();
        $plan = $this->planForProduct('retail');
        $sub  = $this->makeSubscription($shop, $plan, 'active');

        $this->grantViaSubscription($shop, $sub);

        $row = $this->activeEdition($shop, ShopEdition::RETAILER);
        $this->assertNotNull($row, 'retail-only shop must hold the retailer edition');
        $this->assertSame(ShopEditionAssignment::SOURCE_SUBSCRIPTION, $row->source);
        $this->assertSame($sub->id, $row->product_subscription_id);

        // No dhiran.
        $this->assertNull($this->activeEdition($shop, ShopEdition::DHIRAN));

        // ERP writable.
        TenantContext::runFor($shop->id, fn () => SubscriptionGateService::assertShopWritable($shop->id));
        $this->assertTrue(true);
    }

    // ── (B) Dhiran-only (no retail) ─────────────────────────────────

    public function test_dhiran_only_grants_dhiran_edition_and_is_writable_without_retail(): void
    {
        $shop = $this->bareShop();
        $plan = $this->planForProduct('dhiran');
        $sub  = $this->makeSubscription($shop, $plan, 'active');

        $this->grantViaSubscription($shop, $sub);

        $row = $this->activeEdition($shop, ShopEdition::DHIRAN);
        $this->assertNotNull($row, 'dhiran-only shop must hold the dhiran edition');
        $this->assertSame(ShopEditionAssignment::SOURCE_SUBSCRIPTION, $row->source);

        // No retail edition.
        $this->assertNull($this->activeEdition($shop, ShopEdition::RETAILER));

        // A Dhiran-only shop must NOT be blocked from writes for lacking retail.
        TenantContext::runFor($shop->id, fn () => SubscriptionGateService::assertShopWritable($shop->id));
        $this->assertTrue(true);
    }

    // ── (C) Retail + Dhiran ─────────────────────────────────────────

    public function test_retail_plus_dhiran_two_subscriptions_two_editions_both_subscription_sourced(): void
    {
        $shop = $this->bareShop();

        $retailSub = $this->makeSubscription($shop, $this->planForProduct('retail'), 'active');
        $this->grantViaSubscription($shop, $retailSub);

        $dhiranSub = $this->makeSubscription($shop, $this->planForProduct('dhiran'), 'active');
        $this->grantViaSubscription($shop, $dhiranSub);

        // Two distinct subscriptions.
        $this->assertSame(2, ShopSubscription::where('shop_id', $shop->id)->count());

        $retailRow = $this->activeEdition($shop, ShopEdition::RETAILER);
        $dhiranRow = $this->activeEdition($shop, ShopEdition::DHIRAN);

        $this->assertNotNull($retailRow);
        $this->assertNotNull($dhiranRow);
        $this->assertSame(ShopEditionAssignment::SOURCE_SUBSCRIPTION, $retailRow->source);
        $this->assertSame(ShopEditionAssignment::SOURCE_SUBSCRIPTION, $dhiranRow->source);
        $this->assertSame($retailSub->id, $retailRow->product_subscription_id);
        $this->assertSame($dhiranSub->id, $dhiranRow->product_subscription_id);
    }

    // ── activation grants edition (via real createSubscription path) ─

    public function test_activation_via_create_subscription_grants_edition(): void
    {
        $this->createPlatformAdmin();
        $shop = $this->bareShop();
        $user = User::factory()->create(['shop_id' => $shop->id, 'is_active' => true]);
        $this->actingAs($user);

        $plan = $this->planForProduct('retail');

        $sub = app(SubscriptionPaymentService::class)->createSubscription(
            $plan, 'monthly', 1499.00, 'pay_act_' . uniqid(), 'order_act_' . uniqid()
        );

        $this->assertSame('active', $sub->status);
        $row = $this->activeEdition($shop, ShopEdition::RETAILER);
        $this->assertNotNull($row, 'createSubscription must grant the edition');
        $this->assertSame(ShopEditionAssignment::SOURCE_SUBSCRIPTION, $row->source);
        $this->assertSame($sub->id, $row->product_subscription_id);
    }

    // ── full lapse revokes subscription-sourced edition ─────────────

    public function test_full_lapse_revokes_subscription_sourced_edition(): void
    {
        $shop = $this->bareShop();
        $plan = $this->planForProduct('dhiran', ['downgrade_to_read_only_on_due' => false]);
        $sub  = $this->makeSubscription($shop, $plan, 'active', [
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->subDay()->toDateString(),
        ]);
        $this->grantViaSubscription($shop, $sub);

        $this->assertNotNull($this->activeEdition($shop, ShopEdition::DHIRAN));

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $sub->refresh();
        $this->assertSame('expired', $sub->status);
        $this->assertNull(
            $this->activeEdition($shop, ShopEdition::DHIRAN),
            'A fully-lapsed subscription edition must be revoked when nothing else backs it.'
        );
    }

    // ── admin grant survives lapse ──────────────────────────────────

    public function test_admin_grant_edition_survives_subscription_lapse(): void
    {
        $shop = $this->bareShop();

        // Admin grant for dhiran (no payment).
        $admin = $this->createPlatformAdmin();
        ShopEdition::grantTo($shop, ShopEdition::DHIRAN, null);
        $grantRow = $this->activeEdition($shop, ShopEdition::DHIRAN);
        $this->assertSame(ShopEditionAssignment::SOURCE_ADMIN_GRANT, $grantRow->source);

        // A separate (irrelevant) expired retail sub lapses — must not touch dhiran.
        $retailPlan = $this->planForProduct('retail', ['downgrade_to_read_only_on_due' => false]);
        $retailSub  = $this->makeSubscription($shop, $retailPlan, 'active', [
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertNotNull(
            $this->activeEdition($shop, ShopEdition::DHIRAN),
            'An admin_grant edition must never be auto-revoked by a subscription lapse.'
        );
    }

    public function test_admin_grant_sets_source_admin_grant(): void
    {
        $shop = $this->bareShop();
        ShopEdition::grantTo($shop, ShopEdition::MANUFACTURER, null);

        $row = $this->activeEdition($shop, ShopEdition::MANUFACTURER);
        $this->assertSame(ShopEditionAssignment::SOURCE_ADMIN_GRANT, $row->source);
        $this->assertNull($row->product_subscription_id);
    }

    public function test_subscription_lapse_keeps_edition_when_admin_grant_also_backs_it(): void
    {
        $shop = $this->bareShop();

        // First a paid dhiran subscription grants the edition.
        $plan = $this->planForProduct('dhiran', ['downgrade_to_read_only_on_due' => false]);
        $sub  = $this->makeSubscription($shop, $plan, 'active', [
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->subDay()->toDateString(),
        ]);
        $this->grantViaSubscription($shop, $sub);

        // Then an admin grant for the SAME edition — source upgrades to a
        // lapse-immune grant per the source-preservation rule.
        ShopEdition::grantTo($shop, ShopEdition::DHIRAN, null);
        $this->assertSame(
            ShopEditionAssignment::SOURCE_ADMIN_GRANT,
            $this->activeEdition($shop, ShopEdition::DHIRAN)->source
        );

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertNotNull(
            $this->activeEdition($shop, ShopEdition::DHIRAN),
            'Edition must stay active while an admin_grant backs it, even after the sub lapses.'
        );
    }

    // ── staff_limit from retail when both ───────────────────────────

    public function test_staff_limit_comes_from_retail_subscription_when_both_present(): void
    {
        $shop = $this->bareShop();

        // Dhiran sub created FIRST (higher id later) with a different staff_limit.
        $retailSub = $this->makeSubscription($shop, $this->planForProduct('retail', ['features' => ['staff_limit' => 8]]), 'active');
        $this->grantViaSubscription($shop, $retailSub);

        $dhiranSub = $this->makeSubscription($shop, $this->planForProduct('dhiran', ['features' => ['staff_limit' => 2]]), 'active');
        $this->grantViaSubscription($shop, $dhiranSub);

        // Latest sub is dhiran (staff_limit=2) but staffLimit() must use retail (8).
        $shop->refresh()->load('subscriptions');
        $this->assertSame(8, $shop->staffLimit(), 'staff_limit must come from the retail product subscription.');
    }

    public function test_staff_limit_falls_back_to_latest_for_single_product_shop(): void
    {
        $shop = $this->bareShop();
        $dhiranSub = $this->makeSubscription($shop, $this->planForProduct('dhiran', ['features' => ['staff_limit' => 3]]), 'active');
        $this->grantViaSubscription($shop, $dhiranSub);

        $this->assertSame(3, $shop->fresh()->staffLimit());
    }

    // ── gate blocks only unentitled product ─────────────────────────

    public function test_gate_blocks_shop_with_no_entitled_editions(): void
    {
        $shop = $this->bareShop();
        $plan = $this->planForProduct('dhiran', ['downgrade_to_read_only_on_due' => false]);
        $sub  = $this->makeSubscription($shop, $plan, 'active', [
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->subDay()->toDateString(),
        ]);
        $this->grantViaSubscription($shop, $sub);

        // Lapse it so the only edition's backing is gone.
        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Write operations are blocked');

        TenantContext::runFor($shop->id, fn () => SubscriptionGateService::assertShopWritable($shop->id));
    }

    public function test_gate_allows_when_one_product_entitled_even_if_another_lapsed(): void
    {
        $shop = $this->bareShop();

        // Active retail sub → writable retail edition.
        $retailSub = $this->makeSubscription($shop, $this->planForProduct('retail'), 'active');
        $this->grantViaSubscription($shop, $retailSub);

        // A lapsed dhiran sub on the same shop (read_only-disabled so it expires).
        $dhiranPlan = $this->planForProduct('dhiran', ['downgrade_to_read_only_on_due' => false]);
        $dhiranSub  = $this->makeSubscription($shop, $dhiranPlan, 'active', [
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->subDay()->toDateString(),
        ]);
        $this->grantViaSubscription($shop, $dhiranSub);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        // Retail is still entitled → shop remains writable.
        TenantContext::runFor($shop->id, fn () => SubscriptionGateService::assertShopWritable($shop->id));
        $this->assertTrue(true);
    }

    // ── dhiran standalone purchase grants dhiran without retail ──────

    public function test_dhiran_standalone_purchase_grants_dhiran_without_retail(): void
    {
        $this->createPlatformAdmin();
        $shop = $this->bareShop();
        $user = User::factory()->create(['shop_id' => $shop->id, 'is_active' => true]);
        $this->actingAs($user);

        $plan = $this->planForProduct('dhiran');

        $sub = app(SubscriptionPaymentService::class)->createSubscription(
            $plan, 'monthly', 1499.00, 'pay_dh_' . uniqid(), 'order_dh_' . uniqid()
        );

        $this->assertNotNull($this->activeEdition($shop, ShopEdition::DHIRAN));
        $this->assertNull($this->activeEdition($shop, ShopEdition::RETAILER));
        $this->assertSame($sub->id, $this->activeEdition($shop, ShopEdition::DHIRAN)->product_subscription_id);
    }

    // ── renewal keeps edition ───────────────────────────────────────

    public function test_renewal_keeps_edition_and_repoints_subscription(): void
    {
        $shop = $this->bareShop();
        $plan = $this->planForProduct('retail', ['grace_days' => 14]);
        $current = $this->makeSubscription($shop, $plan, 'active', [
            'starts_at'     => now()->subMonths(1)->toDateString(),
            'ends_at'       => now()->addDays(5)->toDateString(),
            'grace_ends_at' => now()->addDays(19)->toDateString(),
            'billing_cycle' => 'monthly',
        ]);
        $this->grantViaSubscription($shop, $current);

        $renewed = app(SubscriptionPaymentService::class)->renewSubscription(
            $current, 'monthly', 1499.00, 'pay_renew_' . uniqid(), 'order_renew_' . uniqid()
        );

        $row = $this->activeEdition($shop, ShopEdition::RETAILER);
        $this->assertNotNull($row, 'Renewal must keep the edition active.');
        $this->assertSame($renewed->id, $row->product_subscription_id, 'Edition should re-point at the renewed subscription.');
        $this->assertSame(ShopEditionAssignment::SOURCE_SUBSCRIPTION, $row->source);
    }

    // ── grant is idempotent (no duplicate rows) ─────────────────────

    public function test_subscription_grant_is_idempotent(): void
    {
        $shop = $this->bareShop();
        $plan = $this->planForProduct('retail');
        $sub  = $this->makeSubscription($shop, $plan, 'active');

        $this->grantViaSubscription($shop, $sub);
        $this->grantViaSubscription($shop, $sub);
        $this->grantViaSubscription($shop, $sub);

        $count = ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('edition', ShopEdition::RETAILER)
            ->count();

        $this->assertSame(1, $count, 'Repeated grants must not duplicate the edition row.');
    }
}
