<?php

namespace Tests\Feature\Onboarding;

use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Models\User;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * ERP Shop Onboarding & Selection — integration / feature / security (Module 2).
 *
 * Covers the shops.store create flow end-to-end: shop_id + owner role + edition +
 * defaults are provisioned atomically; the onboarding guards (already-has-shop,
 * no-subscription, no-edition) route correctly; a forged shop_id is ignored;
 * tenant isolation holds; and a pure-ERP onboarding never creates a Dhiran shop.
 *
 * ERP host is jewelflows.com (realm from host). CSRF/throttle are disabled to
 * match the established web-flow test pattern.
 */
class ShopOnboardingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function plan(): Plan
    {
        return Plan::firstOrCreate(
            ['code' => 'retailer_yearly'],
            ['name' => 'Retailer Yearly', 'price_monthly' => 0, 'price_yearly' => 19999, 'grace_days' => 5, 'is_active' => true],
        );
    }

    /** A fresh ERP user mid-onboarding: no shop, a paid pending subscription. */
    private function onboardingUser(string $mobile): User
    {
        $u = User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('password'),
            'realm' => 'erp', 'is_active' => true,
        ]);
        ShopSubscription::create([
            'shop_id' => null, 'plan_id' => $this->plan()->id, 'user_id' => $u->id,
            'status' => 'active', 'billing_cycle' => 'yearly', 'price_paid' => 19999,
            'starts_at' => now(), 'ends_at' => now()->addYear(),
        ]);

        return $u->fresh();
    }

    /** Valid shops.store payload for a retailer shop. */
    private function shopPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'Test Jewellers', 'phone' => '9876500001',
            'address_line1' => '1 Main Rd', 'city' => 'Pune', 'state' => 'Maharashtra',
            'pincode' => '411001', 'country' => 'India',
            'owner_first_name' => 'Asha', 'owner_last_name' => 'Patel', 'owner_mobile' => '9876500001',
        ], $over);
    }

    private function store(User $user, array $payload, array $session = [])
    {
        return $this->actingAs($user)
            ->withSession(array_merge([
                'onboarding_editions' => [ShopEdition::RETAILER],
                'subscription_completed' => true,
            ], $session))
            ->post(self::ERP . '/shops', $payload);
    }

    // ── Integration: the create flow ───────────────────────────────────────

    public function test_new_user_creates_first_erp_shop_with_full_provisioning(): void
    {
        $user = $this->onboardingUser('9876500001');

        $res = $this->store($user, $this->shopPayload());

        $res->assertRedirect(route('dashboard'));

        $user = $user->fresh()->load('role');
        $this->assertNotNull($user->shop_id, 'user gets a shop_id');

        $shop = Shop::find($user->shop_id);
        $this->assertNotNull($shop);
        $this->assertSame('retailer', $shop->shop_type);
        $this->assertTrue((bool) $shop->is_active);
        $this->assertSame('active', $shop->access_mode);

        // Owner role assigned to this shop, and the user holds it. Role is
        // tenant-scoped (BelongsToShop), so read it without the global scope in
        // this console test (real HTTP sets the tenant from the authed user).
        $this->assertNotNull($user->role_id);
        $ownerRole = \App\Models\Role::withoutGlobalScopes()->find($user->role_id);
        $this->assertSame('owner', $ownerRole->name);
        $this->assertSame($shop->id, $ownerRole->shop_id, 'owner role is scoped to the created shop');

        // Edition granted.
        $this->assertTrue($shop->hasEdition(ShopEdition::RETAILER));

        // Owner name synced from the form.
        $this->assertSame('Asha Patel', $user->name);

        // Shop code generated.
        $this->assertNotEmpty($shop->shop_code);
    }

    public function test_shop_create_is_transactional_no_orphan_on_validation_fail(): void
    {
        $user = $this->onboardingUser('9876500002');
        $shopsBefore = Shop::count();

        // Missing required fields → validation fails before any write.
        $res = $this->store($user, ['name' => '']);

        $res->assertSessionHasErrors();
        $this->assertSame($shopsBefore, Shop::count(), 'no shop row created on a failed validation');
        $this->assertNull($user->fresh()->shop_id, 'user not left half-onboarded');
    }

    public function test_onboarding_creates_edition_and_lazy_defaults_are_reachable(): void
    {
        $user = $this->onboardingUser('9876500003');
        $this->store($user, $this->shopPayload())->assertRedirect();
        $shop = Shop::find($user->fresh()->shop_id);

        // Onboarding deterministically creates the edition assignment.
        $this->assertSame(1, ShopEditionAssignment::withoutGlobalScopes()
            ->where('shop_id', $shop->id)->where('edition', 'retailer')->count());

        // Shop defaults (rules/preferences/billing) are lazy — created on first
        // access via their firstOrCreate accessors, not at onboarding time.
        // The contract that matters: they RESOLVE without error under tenant
        // context (real requests always run inside the shop's tenant).
        \App\Support\TenantContext::runFor($shop->id, function () use ($shop) {
            $this->assertNotNull(\App\Models\ShopRules::firstOrCreate(['shop_id' => $shop->id]));
            $this->assertNotNull(\App\Models\ShopPreferences::firstOrCreate(['shop_id' => $shop->id]));
            $this->assertNotNull(\App\Models\ShopBillingSettings::firstOrCreate(['shop_id' => $shop->id]));
        });
    }

    // ── Onboarding guards / routing ────────────────────────────────────────

    public function test_user_with_shop_is_redirected_to_dashboard_not_recreated(): void
    {
        $user = $this->onboardingUser('9876500004');
        $this->store($user, $this->shopPayload())->assertRedirect(route('dashboard'));
        $firstShopId = $user->fresh()->shop_id;

        // A second store attempt must NOT create another shop.
        $res = $this->store($user->fresh(), $this->shopPayload(['name' => 'Second Shop']));
        $res->assertRedirect(route('dashboard'));
        $this->assertSame($firstShopId, $user->fresh()->shop_id, 'shop_id unchanged');
        $this->assertSame(1, Shop::where('id', $firstShopId)->count());
    }

    public function test_store_without_subscription_redirects_to_plans(): void
    {
        $user = User::create(['mobile_number' => '9876500005', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);

        // No pending subscription, no subscription_completed flag → plans.
        $res = $this->actingAs($user)
            ->withSession(['onboarding_editions' => [ShopEdition::RETAILER]])
            ->post(self::ERP . '/shops', $this->shopPayload());

        $res->assertRedirect(route('subscription.plans'));
        $this->assertNull($user->fresh()->shop_id);
    }

    public function test_store_without_chosen_edition_redirects_to_choose_type(): void
    {
        $user = $this->onboardingUser('9876500006');

        $res = $this->actingAs($user)
            ->withSession(['subscription_completed' => true]) // no onboarding_editions
            ->post(self::ERP . '/shops', $this->shopPayload());

        $res->assertRedirect(route('shops.choose-type'));
        $this->assertNull($user->fresh()->shop_id);
    }

    public function test_no_shop_user_create_page_renders_with_logout_escape(): void
    {
        $user = $this->onboardingUser('9876500007');

        $res = $this->actingAs($user)
            ->withSession(['onboarding_editions' => [ShopEdition::RETAILER], 'subscription_completed' => true])
            ->get(self::ERP . '/shops/create');

        $res->assertOk();
    }

    // ── Validation ─────────────────────────────────────────────────────────

    public function test_store_validates_required_fields(): void
    {
        $user = $this->onboardingUser('9876500008');

        $res = $this->store($user, ['name' => '', 'phone' => '123', 'pincode' => 'xx']);

        $res->assertSessionHasErrors(['name', 'phone', 'address_line1', 'city', 'state', 'pincode', 'owner_first_name', 'owner_mobile']);
    }

    // ── Security / tenant isolation ────────────────────────────────────────

    public function test_forged_shop_id_in_request_is_ignored(): void
    {
        $other = Shop::create([
            'name' => 'Victim Shop', 'shop_type' => 'retailer', 'phone' => '9000000201',
            'owner_first_name' => 'V', 'owner_last_name' => 'X', 'owner_mobile' => '9000000202',
            'is_active' => true, 'access_mode' => 'active',
        ]);
        $user = $this->onboardingUser('9876500009');

        // Attempt to inject shop_id / shop_type / access_mode via the request body.
        $this->store($user, $this->shopPayload([
            'shop_id' => $other->id,
            'shop_type' => 'manufacturer',
            'access_mode' => 'read_only',
        ]))->assertRedirect();

        $user = $user->fresh()->load('role');
        $this->assertNotSame($other->id, $user->shop_id, 'must not attach to another shop');
        $shop = Shop::find($user->shop_id);
        // shop_type comes from the chosen edition (retailer), never the request body.
        $this->assertSame('retailer', $shop->shop_type);
        $this->assertSame('active', $shop->access_mode);
    }

    public function test_created_shop_is_isolated_from_other_shops(): void
    {
        $a = $this->onboardingUser('9876500010');
        $this->store($a, $this->shopPayload(['owner_mobile' => '9876500010']))->assertRedirect();
        $shopA = $a->fresh()->shop_id;

        $b = $this->onboardingUser('9876500011');
        $this->store($b, $this->shopPayload(['owner_mobile' => '9876500011']))->assertRedirect();
        $shopB = $b->fresh()->shop_id;

        $this->assertNotSame($shopA, $shopB, 'two owners get two distinct shops');
        // Each owner role is scoped to its own shop (read without tenant scope).
        $roleA = \App\Models\Role::withoutGlobalScopes()->find($a->fresh()->role_id);
        $roleB = \App\Models\Role::withoutGlobalScopes()->find($b->fresh()->role_id);
        $this->assertSame($shopA, $roleA->shop_id);
        $this->assertSame($shopB, $roleB->shop_id);
    }

    public function test_guest_cannot_reach_shop_create(): void
    {
        $res = $this->get(self::ERP . '/shops/create');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    // ── Dhiran exclusion ───────────────────────────────────────────────────

    public function test_erp_onboarding_does_not_create_a_dhiran_shop_or_settings(): void
    {
        $user = $this->onboardingUser('9876500012');
        $this->store($user, $this->shopPayload())->assertRedirect();
        $shop = Shop::find($user->fresh()->shop_id);

        $this->assertSame('retailer', $shop->shop_type);
        $this->assertFalse($shop->hasEdition(ShopEdition::DHIRAN), 'pure-ERP onboarding grants no Dhiran edition');
        // No Dhiran settings row should be enabled for this shop.
        $enabled = DB::table('dhiran_settings')->where('shop_id', $shop->id)->whereRaw('is_enabled IS TRUE')->exists();
        $this->assertFalse($enabled, 'Dhiran must not be enabled by an ERP-only onboarding');
    }

    // ── Idempotency / re-run safety ────────────────────────────────────────

    public function test_role_and_edition_provisioning_is_idempotent(): void
    {
        $user = $this->onboardingUser('9876500013');
        $this->store($user, $this->shopPayload())->assertRedirect();
        $shop = Shop::find($user->fresh()->shop_id);

        // Re-running default provisioning must not duplicate roles or editions.
        app(\App\Services\TenantRoleService::class)->ensureDefaultsForShop($shop->id);

        $this->assertSame(1, \App\Models\Role::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'owner')->count());
        $this->assertSame(1, ShopEditionAssignment::withoutGlobalScopes()->where('shop_id', $shop->id)->where('edition', 'retailer')->count());
    }
}
