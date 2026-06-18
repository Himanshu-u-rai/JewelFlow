<?php

namespace Tests\Feature;

use App\Console\Commands\CheckSubscriptionExpiry;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformSetting;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use App\Services\SubscriptionTrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Early upgrade: a shop on a free trial may buy a paid plan before the trial
 * ends. It keeps its remaining free days — the paid term begins when the trial
 * ends (no lost days, no read-only gap at the seam). A shop that already holds a
 * live PAID subscription cannot buy again (no stacked term, no double charge).
 */
class TrialToPaidUpgradeTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        config(['platform.enforce_subscriptions' => true]);
        config(['business.subscription_trial_days' => 30]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
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

    private function startTrial(string $shopType = 'retailer'): array
    {
        [$shop, $user] = $this->shopAndUser($shopType);
        $this->actingAs($user);
        $plan = Plan::where('code', $shopType . '_yearly')->firstOrFail();
        $trial = app(SubscriptionPaymentService::class)->startTrial($plan);

        return [$shop, $user, $trial, $plan];
    }

    private function service(): SubscriptionPaymentService
    {
        return app(SubscriptionPaymentService::class);
    }

    // ── Gate: a trial shop CAN reach the buy flow ────────────────────────

    public function test_trialing_shop_can_open_plans_page(): void
    {
        [, $user] = $this->startTrial();

        $this->actingAs($user)->get(route('subscription.plans'))
            ->assertOk()
            ->assertDontSee('already has an active paid subscription');
    }

    public function test_live_paid_shop_is_blocked_from_plans_and_payment(): void
    {
        [$shop, $user, $trial, $plan] = $this->startTrial();
        // Upgrade to paid (active) so the shop now holds a live paid sub.
        ShopSubscription::create([
            'shop_id' => $shop->id, 'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => 'active', 'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->addDays(14)->toDateString(),
            'billing_cycle' => 'yearly', 'price_paid' => 19999, 'actor_type' => 'self_service',
        ]);

        $this->actingAs($user)->get(route('subscription.plans'))
            ->assertRedirect(route('subscription.status'));
    }

    // ── Activation anchoring ─────────────────────────────────────────────

    public function test_early_upgrade_paid_term_starts_when_trial_ends(): void
    {
        [, $user, $trial] = $this->startTrial();
        // Trial ends in 12 days.
        $trialEnd = now()->addDays(12)->startOfDay();
        $trial->forceFill([
            'starts_at' => now()->subDays(18)->toDateString(),
            'ends_at' => $trialEnd->toDateString(),
            'grace_ends_at' => $trialEnd->toDateString(),
        ])->save();

        $this->actingAs($user);
        $paid = $this->service()->createSubscription(
            Plan::where('code', 'retailer_yearly')->firstOrFail(),
            'yearly', 19999.00, 'pay_up_' . uniqid(), 'order_up_' . uniqid()
        );

        $this->assertSame('active', $paid->status);
        // Paid term begins at the trial end (free days preserved), runs one year.
        $this->assertEquals($trialEnd->toDateString(), Carbon::parse($paid->starts_at)->toDateString());
        $this->assertEquals($trialEnd->copy()->addYear()->toDateString(), Carbon::parse($paid->ends_at)->toDateString());
        // Trial row is untouched and still live.
        $trial->refresh();
        $this->assertSame('trial', $trial->status);
        $this->assertEquals($trialEnd->toDateString(), Carbon::parse($trial->ends_at)->toDateString());
    }

    public function test_first_ever_purchase_still_starts_today(): void
    {
        // Regression guard: no current sub → paid term starts now.
        $this->createPlatformAdmin();
        $user = User::factory()->create(['is_active' => true]); // shop_id null
        $this->actingAs($user);

        $paid = $this->service()->createSubscription(
            Plan::where('code', 'retailer_yearly')->firstOrFail(),
            'yearly', 19999.00, 'pay_new_' . uniqid(), 'order_new_' . uniqid()
        );

        $this->assertEquals(now()->toDateString(), Carbon::parse($paid->starts_at)->toDateString());
    }

    public function test_service_refuses_to_stack_on_a_live_paid_subscription(): void
    {
        [$shop, $user, $trial, $plan] = $this->startTrial();
        ShopSubscription::create([
            'shop_id' => $shop->id, 'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => 'active', 'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->addDays(14)->toDateString(),
            'billing_cycle' => 'yearly', 'price_paid' => 19999, 'actor_type' => 'self_service',
        ]);

        $this->actingAs($user);
        $this->expectException(LogicException::class);
        $this->service()->createSubscription(
            $plan, 'yearly', 19999.00, 'pay_stack_' . uniqid(), 'order_stack_' . uniqid()
        );
    }

    // ── Seam continuity: trial lapses but paid row covers the shop ───────

    public function test_expiry_of_upgraded_trial_does_not_downgrade_shop(): void
    {
        [$shop, $user, $trial, $plan] = $this->startTrial();
        // Trial already ended yesterday.
        $trial->forceFill([
            'starts_at' => now()->subDays(31)->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->subDay()->toDateString(),
        ])->save();
        // Paid row bought during the trial, now covering the shop (higher id).
        ShopSubscription::create([
            'shop_id' => $shop->id, 'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => 'active', 'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->addDays(14)->toDateString(),
            'billing_cycle' => 'yearly', 'price_paid' => 19999, 'actor_type' => 'self_service',
        ]);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        // Trial row transitions out of 'trial' (bookkeeping — exact terminal
        // status depends on the plan's downgrade policy)...
        $trial->refresh();
        $this->assertNotSame('trial', $trial->status);
        // ...but the shop stays ACTIVE because the paid row supersedes it.
        $shop->refresh();
        $this->assertSame('active', $shop->access_mode, 'Upgraded shop must not be downgraded when its old trial row lapses.');
        $this->assertTrue((bool) $shop->is_active);
    }

    // ── Admin bulk trial-length recompute skips upgraded shops ───────────

    public function test_bulk_trial_recompute_skips_already_upgraded_shops(): void
    {
        [$shop, $user, $trial, $plan] = $this->startTrial();
        $trial->forceFill([
            'starts_at' => now()->subDays(10)->toDateString(),
            'ends_at' => now()->addDays(20)->toDateString(),
            'grace_ends_at' => now()->addDays(20)->toDateString(),
        ])->save();
        $originalEnd = Carbon::parse($trial->ends_at)->toDateString();
        // This shop already upgraded (paid row pinned to the trial end).
        ShopSubscription::create([
            'shop_id' => $shop->id, 'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => 'active', 'starts_at' => $originalEnd,
            'ends_at' => Carbon::parse($originalEnd)->addYear()->toDateString(),
            'grace_ends_at' => Carbon::parse($originalEnd)->addYear()->addDays(14)->toDateString(),
            'billing_cycle' => 'yearly', 'price_paid' => 19999, 'actor_type' => 'self_service',
        ]);

        $result = app(SubscriptionTrialService::class)->applyTrialLengthToActiveTrials(60, null);

        // The trial of an already-upgraded shop is left frozen.
        $trial->refresh();
        $this->assertEquals($originalEnd, Carbon::parse($trial->ends_at)->toDateString());
        $this->assertSame(1, $result['skipped_upgraded']);
        $this->assertSame(0, $result['updated']);
    }
}
