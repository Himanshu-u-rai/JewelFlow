<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformSetting;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use App\Services\SubscriptionTrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Admin-configurable free-trial length: the PlatformSetting drives new trials,
 * and an explicit admin action recomputes shops already on trial (from their
 * original start date, clamped to today if it would be in the past).
 */
class AdminTrialLengthTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private \App\Models\Platform\PlatformAdmin $admin;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        config(['platform.enforce_subscriptions' => true]);
        config(['business.subscription_trial_days' => 30]);
        $this->seed(\Database\Seeders\PlatformProductSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = $this->createPlatformAdmin();
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

    private function startTrial(string $shopType = 'retailer'): ShopSubscription
    {
        [$shop, $user] = $this->shopAndUser($shopType);
        $this->actingAs($user);
        $plan = Plan::where('code', $shopType . '_yearly')->firstOrFail();

        return app(SubscriptionPaymentService::class)->startTrial($plan);
    }

    // ── Setting drives NEW trials ────────────────────────────────────────

    public function test_trial_days_helper_precedence(): void
    {
        // No setting → config (30).
        $this->assertSame(30, PlatformSetting::trialDays());
        // Setting overrides config.
        PlatformSetting::set('subscription_trial_days', '45');
        $this->assertSame(45, PlatformSetting::trialDays());
        // Clamped to sane range.
        PlatformSetting::set('subscription_trial_days', '9999');
        $this->assertSame(365, PlatformSetting::trialDays());
    }

    public function test_new_trial_uses_admin_setting_not_raw_config(): void
    {
        PlatformSetting::set('subscription_trial_days', '45');

        $sub = $this->startTrial();

        $this->assertSame('trial', $sub->status);
        $this->assertEqualsWithDelta(45, $sub->starts_at->diffInDays($sub->ends_at), 0.5);
    }

    // ── Bulk apply to EXISTING trials ────────────────────────────────────

    public function test_extend_existing_trial_from_original_start(): void
    {
        $sub = $this->startTrial();
        // Pretend it started 10 days ago (30-day trial → ends in 20 days).
        $sub->forceFill([
            'starts_at' => Carbon::now()->subDays(10)->toDateString(),
            'ends_at' => Carbon::now()->addDays(20)->toDateString(),
            'grace_ends_at' => Carbon::now()->addDays(20)->toDateString(),
        ])->save();

        $result = app(SubscriptionTrialService::class)->applyTrialLengthToActiveTrials(45, null);

        $sub->refresh();
        // new ends_at = original start (10 days ago) + 45 days. Compare dates.
        $expected = Carbon::now()->subDays(10)->addDays(45)->toDateString();
        $this->assertEquals($expected, $sub->ends_at->toDateString());
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['clamped']);
        // grace tracks ends_at (trials have no grace).
        $this->assertEquals($sub->ends_at->toDateString(), $sub->grace_ends_at->toDateString());
    }

    public function test_shorten_clamps_past_due_to_today(): void
    {
        $sub = $this->startTrial();
        $sub->forceFill([
            'starts_at' => Carbon::now()->subDays(10)->toDateString(),
            'ends_at' => Carbon::now()->addDays(20)->toDateString(),
            'grace_ends_at' => Carbon::now()->addDays(20)->toDateString(),
        ])->save();

        // Lower to 5 days: start+5 = 5 days ago = past → clamp to today.
        $result = app(SubscriptionTrialService::class)->applyTrialLengthToActiveTrials(5, null);

        $sub->refresh();
        $this->assertEquals(Carbon::now()->toDateString(), $sub->ends_at->toDateString());
        $this->assertSame(1, $result['clamped']);
    }

    public function test_apply_is_idempotent(): void
    {
        $sub = $this->startTrial();
        app(SubscriptionTrialService::class)->applyTrialLengthToActiveTrials(45, null);
        $afterFirst = $sub->refresh()->ends_at->toDateString();

        $result = app(SubscriptionTrialService::class)->applyTrialLengthToActiveTrials(45, null);

        $this->assertEquals($afterFirst, $sub->refresh()->ends_at->toDateString());
        $this->assertSame(0, $result['updated']);   // nothing changed second time
        $this->assertSame(1, $result['unchanged']);
    }

    public function test_writes_audit_event_per_changed_trial(): void
    {
        $sub = $this->startTrial();
        $before = SubscriptionEvent::where('event_type', 'subscription.trial_length_changed')->count();

        app(SubscriptionTrialService::class)->applyTrialLengthToActiveTrials(60, (int) $this->admin->id);

        $events = SubscriptionEvent::where('event_type', 'subscription.trial_length_changed')->get();
        $this->assertSame($before + 1, $events->count());
        $this->assertSame($sub->id, $events->first()->shop_subscription_id);
    }

    // ── daysRemaining() accessor (renewal-copy bug fix) ──────────────────

    public function test_days_remaining_is_whole_integer_not_a_float(): void
    {
        $sub = $this->startTrial();
        // ends in 7 calendar days.
        $sub->forceFill(['ends_at' => Carbon::now()->addDays(7)->toDateString()])->save();

        $days = $sub->refresh()->daysRemaining();

        $this->assertIsInt($days);
        $this->assertSame(7, $days); // not 6.33…
    }

    public function test_days_remaining_zero_on_last_day_and_negative_when_overdue(): void
    {
        $sub = $this->startTrial();

        $sub->forceFill(['ends_at' => Carbon::now()->toDateString()])->save();
        $this->assertSame(0, $sub->refresh()->daysRemaining());

        $sub->forceFill(['ends_at' => Carbon::now()->subDays(3)->toDateString()])->save();
        $this->assertSame(-3, $sub->refresh()->daysRemaining());
    }

    public function test_days_remaining_null_without_end_date(): void
    {
        $sub = $this->startTrial();
        $sub->forceFill(['ends_at' => null])->save();
        $this->assertNull($sub->refresh()->daysRemaining());
    }

    public function test_non_trial_subscriptions_are_untouched(): void
    {
        $sub = $this->startTrial();
        // Flip to active (paid) with a fixed end date.
        $fixedEnd = Carbon::now()->addYear()->toDateString();
        $sub->forceFill(['status' => 'active', 'ends_at' => $fixedEnd, 'grace_ends_at' => $fixedEnd])->save();

        $result = app(SubscriptionTrialService::class)->applyTrialLengthToActiveTrials(45, null);

        $sub->refresh();
        $this->assertEquals($fixedEnd, $sub->ends_at->toDateString(), 'active sub must be untouched');
        $this->assertSame(0, $result['updated']);
    }
}
