<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionGateService;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Free trial: no payment, 1 month, fully writable, drops to read-only at end
 * (data preserved). One trial per family — retailer+manufacturer share the 'erp'
 * family, dhiran is separate.
 */
class FreeTrialTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        config(['platform.enforce_subscriptions' => true]);
        config(['business.subscription_trial_days' => 30]);
        // Use the REAL product/plan catalog.
        $this->seed(\Database\Seeders\PlatformProductSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->createPlatformAdmin();
    }

    private function shopAndUser(string $shopType = 'retailer'): array
    {
        $shop = Shop::create([
            'name' => ucfirst($shopType) . ' Shop', 'shop_type' => $shopType,
            'phone' => fake()->unique()->numerify('9########'),
            'owner_first_name' => 'A', 'owner_last_name' => 'B',
            'owner_mobile' => fake()->unique()->numerify('9########'),
            'is_active' => true, 'access_mode' => 'active',
        ]);
        // The owner role is not decoration here: subscription commerce PROVES
        // ownership from users.role_id and denies an unproven identity, so a
        // fixture that drives the HTTP trial/checkout routes must carry one.
        $user = User::create([
            'name' => 'Owner', 'mobile_number' => fake()->unique()->numerify('9########'),
            'shop_id' => $shop->id, 'role_id' => $this->createOwnerRole($shop->id)->id,
            'password' => bcrypt('x'), 'is_active' => true,
        ]);
        return [$shop, $user];
    }

    public function test_starting_a_trial_grants_edition_no_payment_and_is_writable(): void
    {
        [$shop, $user] = $this->shopAndUser('retailer');
        $this->actingAs($user);
        $plan = Plan::where('code', 'retailer_yearly')->firstOrFail();

        $sub = app(SubscriptionPaymentService::class)->startTrial($plan);

        $this->assertSame('trial', $sub->status);
        $this->assertEquals(0.0, (float) $sub->price_paid, 'trial is free');
        $this->assertNull($sub->razorpay_payment_id, 'no payment id');
        // 30-day window, NO bonus grace (grace_ends_at == ends_at → read-only at end).
        $this->assertEquals(Carbon::parse($sub->starts_at)->addDays(30)->toDateString(),
            Carbon::parse($sub->ends_at)->toDateString());
        $this->assertEquals(Carbon::parse($sub->ends_at)->toDateString(),
            Carbon::parse($sub->grace_ends_at)->toDateString(), 'no extra grace on a trial');
        // Edition granted, shop writable during trial.
        $this->assertTrue($shop->fresh()->hasEdition('retailer'));
        SubscriptionGateService::assertShopWritable($shop->id); // must not throw
        $this->assertTrue(true);
    }

    /**
     * A trial that runs out is a LAPSE, so it lands on the entitlement axis:
     * `expired` + a suspended shop, which is the recoverable state the owner can
     * buy their way out of.
     *
     * It must NOT land on `read_only` — that state is reserved exclusively for a
     * JewelFlows administrator hold. Minting it here was what left non-paying
     * shops with a fully browsable ERP and made an admin hold indistinguishable
     * from an unpaid bill.
     */
    public function test_trial_end_expires_and_suspends_data_preserved(): void
    {
        [$shop, $user] = $this->shopAndUser('retailer');
        $this->actingAs($user);
        $plan = Plan::where('code', 'retailer_yearly')->firstOrFail();
        $sub = app(SubscriptionPaymentService::class)->startTrial($plan);

        // Fast-forward: trial fully ended (past ends_at == grace_ends_at).
        $sub->forceFill([
            'ends_at' => Carbon::now()->subDay(),
            'grace_ends_at' => Carbon::now()->subDay(),
        ])->save();

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $sub->refresh();
        $shop->refresh();
        $this->assertSame('expired', $sub->status, 'trial end → expired, never read_only');
        $this->assertSame('suspended', $shop->access_mode);
        $this->assertNull($shop->suspended_by, 'A lapse must never look administrative.');

        // A lapsed shop cannot write...
        $blocked = false;
        try { SubscriptionGateService::assertShopWritable($shop->id); }
        catch (LogicException $e) { $blocked = true; }
        $this->assertTrue($blocked, 'lapsed shop cannot write');

        // ...yet NOTHING is deleted. The lapse is enforced at the WRITE GATE
        // (asserted above), not by tearing down the shop's edition row: this
        // shop's retailer edition is the `seed` row every shop is born with
        // (Shop::created), and a seed/admin_grant row is deliberately immune to
        // lapse revocation (ShopEdition::revokeFromLapsedSubscription). It is
        // access the shop holds independently of any one payment, so renewing
        // lights the same row back up and the shop's data was never at risk.
        $assignment = \App\Models\ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('edition', 'retailer')
            ->latest('id')
            ->first();

        $this->assertNotNull($assignment, 'the edition assignment row survives the lapse');
        $this->assertSame('seed', $assignment->source, 'the onboarding row, not a paid grant');
        $this->assertNull($assignment->deactivated_at, 'a seed row is immune to lapse revocation');
        $this->assertNull($assignment->deactivated_by, 'no admin acted — this was a lapse');
    }

    public function test_buying_a_plan_during_trial_converts_to_active(): void
    {
        [$shop, $user] = $this->shopAndUser('retailer');
        $this->actingAs($user);
        $plan = Plan::where('code', 'retailer_yearly')->firstOrFail();
        app(SubscriptionPaymentService::class)->startTrial($plan);

        // Buying the plan = normal paid path (new row, active, full term).
        $paid = app(SubscriptionPaymentService::class)->createSubscription(
            $plan, 'yearly', (float) $plan->price_yearly, 'pay_conv', 'order_conv'
        );

        $this->assertSame('active', $paid->status);
        // Inclusive To: full yearly term = start + 1 year − 1 day.
        $this->assertEquals(Carbon::parse($paid->starts_at)->addYearNoOverflow()->subDay()->toDateString(),
            Carbon::parse($paid->ends_at)->toDateString());
        SubscriptionGateService::assertShopWritable($shop->id); // writable as paid
        $this->assertTrue(true);
    }

    public function test_one_trial_per_family_retailer_and_manufacturer_share(): void
    {
        [$shop, $user] = $this->shopAndUser('retailer');
        $this->actingAs($user);
        $svc = app(SubscriptionPaymentService::class);

        $svc->startTrial(Plan::where('code', 'retailer_yearly')->firstOrFail());

        // Manufacturer is the SAME family (erp) → second free trial refused.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already used your free trial');
        $svc->startTrial(Plan::where('code', 'manufacturer_yearly')->firstOrFail());
    }

    public function test_dhiran_is_a_separate_family_can_trial_after_erp(): void
    {
        [$shop, $user] = $this->shopAndUser('retailer');
        $this->actingAs($user);
        $svc = app(SubscriptionPaymentService::class);

        $svc->startTrial(Plan::where('code', 'retailer_yearly')->firstOrFail());
        // Dhiran is a different family → allowed.
        $dhiran = $svc->startTrial(Plan::where('code', 'dhiran_yearly')->firstOrFail());

        $this->assertSame('trial', $dhiran->status);
        $this->assertTrue($shop->fresh()->hasEdition('dhiran'));
        $this->assertTrue($shop->fresh()->hasEdition('retailer'), 'still has the erp trial edition too');
    }

    public function test_http_blocks_a_second_trial_while_one_is_live(): void
    {
        // A shop with a LIVE trial is sent to its subscription status page if it
        // tries to start another (the controller's "already has a subscription"
        // guard). The family-guard itself is covered by the direct-service test.
        [$shop, $user] = $this->shopAndUser('retailer');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->actingAs($user);
        app(SubscriptionPaymentService::class)->startTrial(Plan::where('code', 'retailer_yearly')->firstOrFail());

        $resp = $this->post(route('subscription.trial.start'), [
            'plan_id' => Plan::where('code', 'manufacturer_monthly')->firstOrFail()->id,
        ]);
        $resp->assertRedirect(route('subscription.status'));
        $resp->assertSessionHas('error');
    }

    public function test_http_start_trial_happy_path_redirects_to_dashboard(): void
    {
        // A shop with NO subscription can start a trial via the route and is sent
        // onward (dashboard, since the shop already exists in this test).
        [$shop, $user] = $this->shopAndUser('retailer');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $resp = $this->actingAs($user)->post(route('subscription.trial.start'), [
            'plan_id' => Plan::where('code', 'retailer_yearly')->firstOrFail()->id,
        ]);
        $resp->assertRedirect(route('dashboard'));
        $resp->assertSessionHas('success');
        $this->assertDatabaseHas('shop_subscriptions', [
            'shop_id' => $shop->id, 'status' => 'trial', 'price_paid' => 0,
        ]);
        $this->assertTrue($shop->fresh()->hasEdition('retailer'));
    }

    // ── Hardening (security-review findings) ────────────────────────

    public function test_pre_shop_user_cannot_mint_a_second_trial_of_same_family(): void
    {
        // A user with NO shop yet (onboarding) — the family guard must still fire,
        // keyed on user_id, even though shop_id is null.
        $user = User::create([
            'name' => 'PreShop', 'mobile_number' => fake()->unique()->numerify('9########'),
            'shop_id' => null, 'password' => bcrypt('x'), 'is_active' => true,
        ]);
        $this->actingAs($user);
        $svc = app(SubscriptionPaymentService::class);

        $first = $svc->startTrial(Plan::where('code', 'retailer_yearly')->firstOrFail());
        $this->assertNull($first->shop_id, 'pre-shop trial has null shop_id');
        $this->assertSame($user->id, $first->user_id);

        // Same family (manufacturer = erp), still pre-shop → must be refused.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already used your free trial');
        $svc->startTrial(Plan::where('code', 'manufacturer_yearly')->firstOrFail());
    }

    public function test_pre_shop_user_can_still_trial_a_different_family(): void
    {
        $user = User::create([
            'name' => 'PreShop2', 'mobile_number' => fake()->unique()->numerify('9########'),
            'shop_id' => null, 'password' => bcrypt('x'), 'is_active' => true,
        ]);
        $this->actingAs($user);
        $svc = app(SubscriptionPaymentService::class);

        $svc->startTrial(Plan::where('code', 'retailer_yearly')->firstOrFail());
        // Dhiran is a different family → allowed even pre-shop.
        $dhiran = $svc->startTrial(Plan::where('code', 'dhiran_yearly')->firstOrFail());
        $this->assertSame('trial', $dhiran->status);
        $this->assertSame($user->id, $dhiran->user_id);
    }

    public function test_trial_cannot_un_suspend_an_admin_suspended_shop(): void
    {
        [$shop, $user] = $this->shopAndUser('retailer');
        // Admin suspended the shop; its latest sub is expired (so the controller's
        // live-sub guard would pass) — the trial must NOT lift the suspension.
        $shop->forceFill(['access_mode' => 'suspended', 'is_active' => false])->save();
        $this->actingAs($user);

        $blocked = false;
        try {
            app(SubscriptionPaymentService::class)->startTrial(Plan::where('code', 'retailer_yearly')->firstOrFail());
        } catch (LogicException $e) {
            $blocked = true;
            $this->assertStringContainsString('suspended', strtolower($e->getMessage()));
        }
        $this->assertTrue($blocked, 'a suspended shop must not be able to start a trial');
        $this->assertSame('suspended', $shop->fresh()->access_mode, 'suspension not lifted');
        $this->assertDatabaseMissing('shop_subscriptions', [
            'shop_id' => $shop->id, 'status' => 'trial',
        ]);
    }

    public function test_db_index_blocks_a_duplicate_trial_row_for_same_user_plan(): void
    {
        // Defence-in-depth: the partial unique index rejects a second trial row
        // for the same (user, plan). Simulate the race by inserting directly.
        $user = User::create([
            'name' => 'Dup', 'mobile_number' => fake()->unique()->numerify('9########'),
            'shop_id' => null, 'password' => bcrypt('x'), 'is_active' => true,
        ]);
        $this->actingAs($user);
        $plan = Plan::where('code', 'retailer_yearly')->firstOrFail();
        app(SubscriptionPaymentService::class)->startTrial($plan);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        \App\Models\Platform\ShopSubscription::create([
            'shop_id' => null, 'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => 'trial', 'starts_at' => now(), 'ends_at' => now()->addDays(30),
            'grace_ends_at' => now()->addDays(30), 'price_paid' => 0,
            'razorpay_payment_id' => null, 'updated_by_admin_id' => $this->createPlatformAdmin()->id,
            'actor_type' => 'self_service',
        ]);
    }

    /**
     * End-to-end HTTP journey for the shop-less free-trial hotfix: register →
     * choose edition → start trial pre-shop → create the shop → owner access →
     * logout/login persistence. Drives the real routes with auth/tenant/
     * subscription middleware active — this is the path that was 403ing in
     * production (SubscriptionController::startTrial() used the strict owner
     * guard, which is unconditionally false for a shop-less user).
     */
    public function test_shop_less_signup_can_complete_the_full_trial_journey(): void
    {
        $mobile = '9' . fake()->unique()->numerify('#########');

        $this->post(route('register'), [
            'mobile_number' => $mobile,
            'password' => 'Passw0rd!123',
            'password_confirmation' => 'Passw0rd!123',
        ])->assertRedirect(route('shops.choose-type'));

        $user = User::where('mobile_number', $mobile)->firstOrFail();
        // Registration's Auth::login() cached a partially-hydrated in-memory
        // User (created via ::create(), never re-SELECTed — DB-default columns
        // like is_active are absent from its attribute array). That object
        // stays cached on the Guard for the rest of THIS test process (unlike
        // production, where every request re-hydrates via a fresh Guard). Swap
        // in a fully-loaded model so later requests see the real is_active.
        $this->actingAs($user);

        $this->post(route('shops.choose-type'), ['edition' => 'manufacturer'])
            ->assertRedirect(route('subscription.plans'));

        $plan = Plan::where('code', 'manufacturer_yearly')->firstOrFail();

        // Step 3: start the trial before any shop exists — this is the failing
        // step in production. Fresh-close: must redirect to shop creation, not
        // 403 "Access Restricted".
        $this->post(route('subscription.trial.start'), ['plan_id' => $plan->id])
            ->assertRedirect(route('shops.create', ['type' => 'manufacturer']));

        // Step 4: exactly one pending trial, no payment, no shop attached yet.
        $this->assertSame(1, ShopSubscription::where('user_id', $user->id)->count());
        $subscription = ShopSubscription::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('trial', $subscription->status);
        $this->assertNull($subscription->shop_id);
        $this->assertSame(0.0, (float) $subscription->price_paid);

        // Step 5: create the shop through the real route.
        $this->post(route('shops.store'), [
            'name' => 'Journey Test Shop',
            'phone' => '9' . fake()->unique()->numerify('#########'),
            'address_line1' => '1 Test Street',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400001',
            'owner_first_name' => 'Jane',
            'owner_last_name' => 'Doe',
            'owner_mobile' => '9' . fake()->unique()->numerify('#########'),
            'gst_rate' => 3,
            'wastage_recovery_percent' => 10,
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertNotNull($user->shop_id, 'owner is now attached to the new shop');

        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        $this->assertSame('owner', $role->name);

        $subscription->refresh();
        $this->assertSame($user->shop_id, $subscription->shop_id, 'the pending trial is linked to the new shop');
        $this->assertTrue(
            \App\Models\ShopEditionAssignment::where('shop_id', $user->shop_id)
                ->where('edition', 'manufacturer')->whereNull('deactivated_at')->exists(),
            'manufacturer edition was granted'
        );

        // Opening-setup is a separate, unrelated gate — stamp it complete so
        // "usable dashboard access" isn't conflated with that other feature.
        $this->markShopOpeningSetupComplete($user->shop_id);
        $this->get(route('dashboard'))->assertOk();

        // Step 6: logout / login — onboarding-complete state persists (DB-backed
        // via users.shop_id, not session).
        $this->post(route('logout'))->assertRedirect('/login');
        $this->assertGuest();

        $this->post(route('login'), [
            'mobile_number' => $mobile,
            'password' => 'Passw0rd!123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
        $this->get(route('dashboard'))->assertOk();

        // Also verify: repeating the trial submission cannot mint a second
        // entitlement — the owner now has a shop, so the strict guard applies
        // and blocksNewPaidTerm()/the live-trial check refuses it.
        $this->post(route('subscription.trial.start'), ['plan_id' => $plan->id])
            ->assertRedirect(route('subscription.status'));
        $this->assertSame(1, ShopSubscription::where('user_id', $user->id)->count(), 'no duplicate trial created');
    }
}
