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
        $user = User::create([
            'name' => 'Owner', 'mobile_number' => fake()->unique()->numerify('9########'),
            'shop_id' => $shop->id, 'password' => bcrypt('x'), 'is_active' => true,
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

    public function test_trial_end_drops_shop_to_read_only_data_preserved(): void
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
        $this->assertSame('read_only', $sub->status, 'trial end → read_only, not expired/suspended');
        $this->assertSame('read_only', $shop->access_mode);

        // Read-only blocks writes...
        $blocked = false;
        try { SubscriptionGateService::assertShopWritable($shop->id); }
        catch (LogicException $e) { $blocked = true; }
        $this->assertTrue($blocked, 'read-only shop cannot write');

        // ...but the edition row is preserved (data still belongs to the shop).
        $this->assertTrue($shop->hasEdition('retailer'), 'edition not removed — data preserved');
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
        $this->assertEquals(Carbon::parse($paid->starts_at)->addYear()->toDateString(),
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
}
