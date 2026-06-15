<?php

namespace Tests\Feature;

use App\Console\Commands\RepairTrialTermSubscriptions;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use App\Services\SubscriptionWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'code' => 'retailer_test_' . fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Test Plan',
            'price_monthly' => 1999,
            'price_yearly' => 19999,
            'trial_days' => 0,
            'grace_days' => 14,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
        ], $overrides));
    }

    private function paymentService(): SubscriptionPaymentService
    {
        return app(SubscriptionPaymentService::class);
    }

    private function webhookService(): SubscriptionWebhookService
    {
        return app(SubscriptionWebhookService::class);
    }

    /**
     * Build a persisted subscription row directly (no Razorpay needed).
     */
    private function makeSubscription(array $overrides = []): ShopSubscription
    {
        $admin = PlatformAdmin::where('role', 'super_admin')->first() ?? $this->createPlatformAdmin();
        $plan = $overrides['plan'] ?? $this->makePlan();
        unset($overrides['plan']);

        return ShopSubscription::create(array_merge([
            'shop_id' => null,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->addDays(14)->toDateString(),
            'billing_cycle' => 'yearly',
            'price_paid' => 19999,
            'razorpay_payment_id' => 'pay_' . fake()->unique()->bothify('??########'),
            'razorpay_order_id' => 'order_' . fake()->unique()->bothify('??########'),
            'updated_by_admin_id' => $admin->id,
            'actor_type' => 'self_service',
        ], $overrides));
    }

    // ── Fix 2: createSubscription term calc ─────────────────────────

    public function test_paid_yearly_purchase_grants_full_year_even_if_plan_has_trial_days(): void
    {
        // THE BUG-CATCHER. A plan carrying trial_days must NOT shrink a paid term.
        $this->createPlatformAdmin();
        $user = User::factory()->create(['is_active' => true]); // shop_id null → no invoice path
        $plan = $this->makePlan(['trial_days' => 7]);

        $this->actingAs($user);

        $sub = $this->paymentService()->createSubscription(
            $plan, 'yearly', 19999.00, 'pay_year_bug_' . uniqid(), 'order_year_bug_' . uniqid()
        );

        $this->assertSame('active', $sub->status, 'A paid purchase must be active, never trial.');
        $this->assertSame(
            Carbon::parse($sub->starts_at)->addYear()->toDateString(),
            Carbon::parse($sub->ends_at)->toDateString(),
            'Yearly term must be exactly one year, not a 7-day trial.'
        );
    }

    public function test_paid_monthly_purchase_grants_one_month(): void
    {
        $this->createPlatformAdmin();
        $user = User::factory()->create(['is_active' => true]);
        $plan = $this->makePlan(['trial_days' => 7]);

        $this->actingAs($user);

        $sub = $this->paymentService()->createSubscription(
            $plan, 'monthly', 1999.00, 'pay_month_' . uniqid(), 'order_month_' . uniqid()
        );

        $this->assertSame('active', $sub->status);
        $this->assertSame(
            Carbon::parse($sub->starts_at)->addMonth()->toDateString(),
            Carbon::parse($sub->ends_at)->toDateString()
        );
    }

    public function test_grace_ends_at_equals_ends_at_plus_plan_grace_days(): void
    {
        $this->createPlatformAdmin();
        $user = User::factory()->create(['is_active' => true]);
        $plan = $this->makePlan(['grace_days' => 14]);

        $this->actingAs($user);

        $sub = $this->paymentService()->createSubscription(
            $plan, 'yearly', 19999.00, 'pay_grace_' . uniqid(), 'order_grace_' . uniqid()
        );

        $this->assertSame(
            Carbon::parse($sub->ends_at)->addDays(14)->toDateString(),
            Carbon::parse($sub->grace_ends_at)->toDateString()
        );
    }

    // ── Fix 3: payment.captured webhook ─────────────────────────────

    public function test_payment_captured_webhook_keeps_full_term(): void
    {
        $sub = $this->makeSubscription([
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'billing_cycle' => 'yearly',
        ]);
        $originalEndsAt = Carbon::parse($sub->ends_at)->toDateString();

        $this->webhookService()->handlePaymentCaptured([
            'payload' => ['payment' => ['entity' => ['id' => $sub->razorpay_payment_id]]],
        ]);

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame($originalEndsAt, Carbon::parse($sub->ends_at)->toDateString(),
            'A full-term subscription must not be shrunk by payment.captured.');
    }

    public function test_payment_captured_webhook_promotes_trial_and_recomputes_shrunken_term(): void
    {
        // A legacy buggy sub: trial status + 7-day window on a yearly cycle.
        $sub = $this->makeSubscription([
            'status' => 'trial',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addDays(7)->toDateString(),
            'grace_ends_at' => now()->addDays(7)->addDays(14)->toDateString(),
            'billing_cycle' => 'yearly',
        ]);

        $this->webhookService()->handlePaymentCaptured([
            'payload' => ['payment' => ['entity' => ['id' => $sub->razorpay_payment_id]]],
        ]);

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame(
            Carbon::parse($sub->starts_at)->addYear()->toDateString(),
            Carbon::parse($sub->ends_at)->toDateString(),
            'A shrunken yearly term must be recomputed to a full year on capture.'
        );
    }

    public function test_duplicate_payment_captured_webhook_is_noop(): void
    {
        $sub = $this->makeSubscription([
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'billing_cycle' => 'yearly',
        ]);
        $payload = ['payload' => ['payment' => ['entity' => ['id' => $sub->razorpay_payment_id]]]];

        $this->webhookService()->handlePaymentCaptured($payload);
        $sub->refresh();
        $endsAfterFirst = Carbon::parse($sub->ends_at)->toDateString();

        // Second delivery must change nothing.
        $this->webhookService()->handlePaymentCaptured($payload);
        $sub->refresh();

        $this->assertSame('active', $sub->status);
        $this->assertSame($endsAfterFirst, Carbon::parse($sub->ends_at)->toDateString());
    }

    // ── Fix 3: refund webhook ───────────────────────────────────────

    public function test_full_refund_cancels_subscription(): void
    {
        $sub = $this->makeSubscription(['price_paid' => 19999, 'status' => 'active']);

        $this->webhookService()->handleRefundCreated([
            'payload' => ['refund' => ['entity' => [
                'payment_id' => $sub->razorpay_payment_id,
                'id' => 'rfnd_full_1',
                'amount' => 1999900, // ₹19,999 in paise == price_paid
            ]]],
        ]);

        $sub->refresh();
        $this->assertSame('cancelled', $sub->status);
        $this->assertNotNull($sub->cancelled_at);
        $this->assertDatabaseHas('subscription_events', [
            'shop_subscription_id' => $sub->id,
            'event_type' => 'subscription.refunded',
        ]);
    }

    public function test_partial_refund_keeps_subscription_active_and_logs_event(): void
    {
        $sub = $this->makeSubscription(['price_paid' => 19999, 'status' => 'active']);

        $this->webhookService()->handleRefundCreated([
            'payload' => ['refund' => ['entity' => [
                'payment_id' => $sub->razorpay_payment_id,
                'id' => 'rfnd_partial_1',
                'amount' => 500000, // ₹5,000 < price_paid
            ]]],
        ]);

        $sub->refresh();
        $this->assertSame('active', $sub->status, 'Partial refund must not change subscription status.');
        $this->assertNull($sub->cancelled_at);
        $this->assertDatabaseHas('subscription_events', [
            'shop_subscription_id' => $sub->id,
            'event_type' => 'subscription.partial_refund',
        ]);
        $this->assertDatabaseMissing('subscription_events', [
            'shop_subscription_id' => $sub->id,
            'event_type' => 'subscription.refunded',
        ]);
    }

    // ── Fix 5: renewal path ─────────────────────────────────────────

    public function test_renew_before_expiry_creates_new_row_anchored_at_old_ends_at(): void
    {
        $plan = $this->makePlan(['grace_days' => 14]);
        $current = $this->makeSubscription([
            'plan' => $plan,
            'status' => 'active',
            'starts_at' => now()->subMonths(6)->toDateString(),
            'ends_at' => now()->addMonths(6)->toDateString(), // not yet expired
            'grace_ends_at' => now()->addMonths(6)->addDays(14)->toDateString(),
            'billing_cycle' => 'yearly',
        ]);
        $oldEndsAt = Carbon::parse($current->ends_at)->toDateString();

        $renewed = $this->paymentService()->renewSubscription(
            $current, 'yearly', 19999.00, 'pay_renew_before_' . uniqid(), 'order_renew_before_' . uniqid()
        );

        // Old row untouched.
        $current->refresh();
        $this->assertSame($oldEndsAt, Carbon::parse($current->ends_at)->toDateString());
        $this->assertSame('active', $current->status);

        // New row anchored at old ends_at.
        $this->assertNotSame($current->id, $renewed->id);
        $this->assertSame('active', $renewed->status);
        $this->assertSame($oldEndsAt, Carbon::parse($renewed->starts_at)->toDateString());
        $this->assertSame(
            Carbon::parse($renewed->starts_at)->addYear()->toDateString(),
            Carbon::parse($renewed->ends_at)->toDateString()
        );

        $this->assertDatabaseHas('subscription_events', [
            'shop_subscription_id' => $renewed->id,
            'event_type' => 'subscription.renewed',
        ]);
    }

    public function test_renew_during_grace_extends_from_original_ends_at(): void
    {
        $plan = $this->makePlan(['grace_days' => 14]);
        $current = $this->makeSubscription([
            'plan' => $plan,
            'status' => 'grace',
            'starts_at' => now()->subYear()->subDays(3)->toDateString(),
            'ends_at' => now()->subDays(3)->toDateString(),       // expired 3 days ago
            'grace_ends_at' => now()->addDays(11)->toDateString(), // still in grace
            'billing_cycle' => 'yearly',
        ]);
        $oldEndsAt = Carbon::parse($current->ends_at)->toDateString();

        $renewed = $this->paymentService()->renewSubscription(
            $current, 'yearly', 19999.00, 'pay_renew_grace_' . uniqid(), 'order_renew_grace_' . uniqid()
        );

        $this->assertNotSame($current->id, $renewed->id);
        $this->assertSame($oldEndsAt, Carbon::parse($renewed->starts_at)->toDateString(),
            'During grace the new term must extend from the original ends_at.');
        $this->assertSame(
            Carbon::parse($oldEndsAt)->addYear()->toDateString(),
            Carbon::parse($renewed->ends_at)->toDateString()
        );
    }

    public function test_renew_after_expiry_creates_new_row_from_now(): void
    {
        $plan = $this->makePlan(['grace_days' => 14]);
        $current = $this->makeSubscription([
            'plan' => $plan,
            'status' => 'expired',
            'starts_at' => now()->subYear()->subMonths(2)->toDateString(),
            'ends_at' => now()->subMonths(2)->toDateString(),
            'grace_ends_at' => now()->subMonth()->toDateString(), // grace fully lapsed
            'billing_cycle' => 'yearly',
        ]);

        $renewed = $this->paymentService()->renewSubscription(
            $current, 'yearly', 19999.00, 'pay_renew_after_' . uniqid(), 'order_renew_after_' . uniqid()
        );

        $this->assertNotSame($current->id, $renewed->id);
        $this->assertSame(now()->toDateString(), Carbon::parse($renewed->starts_at)->toDateString(),
            'After full lapse the new term must start today.');
        $this->assertSame(
            now()->addYear()->toDateString(),
            Carbon::parse($renewed->ends_at)->toDateString()
        );
    }

    // ── Fix 6: repair command ───────────────────────────────────────

    public function test_repair_command_dry_run_detects_trial_window_paid_subs_and_writes_nothing(): void
    {
        $plan = $this->makePlan(['grace_days' => 14]);
        // Affected: paid yearly with a 7-day window.
        $affected = $this->makeSubscription([
            'plan' => $plan,
            'status' => 'trial',
            'starts_at' => now()->subDays(2)->toDateString(),
            'ends_at' => now()->subDays(2)->addDays(7)->toDateString(),
            'grace_ends_at' => now()->subDays(2)->addDays(7)->addDays(14)->toDateString(),
            'billing_cycle' => 'yearly',
            'price_paid' => 19999,
        ]);
        // Healthy: full year — must NOT be touched/detected.
        $healthy = $this->makeSubscription([
            'plan' => $plan,
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'billing_cycle' => 'yearly',
            'price_paid' => 19999,
        ]);

        $this->artisan('subscription:repair-trial-terms')
            ->assertExitCode(0);

        // Dry run wrote nothing.
        $affected->refresh();
        $this->assertSame('trial', $affected->status);
        $this->assertSame(
            Carbon::parse($affected->starts_at)->addDays(7)->toDateString(),
            Carbon::parse($affected->ends_at)->toDateString()
        );
        $this->assertDatabaseMissing('subscription_events', [
            'shop_subscription_id' => $affected->id,
            'event_type' => 'subscription.repaired',
        ]);
    }

    public function test_repair_command_commit_fixes_ends_at_and_writes_repaired_event(): void
    {
        $shop = Shop::create([
            'name' => 'Repair Shop',
            'shop_type' => 'retailer',
            'phone' => '9' . fake()->unique()->numerify('#########'),
            'owner_first_name' => 'Repair',
            'owner_last_name' => 'Owner',
            'owner_mobile' => '9' . fake()->unique()->numerify('#########'),
            'access_mode' => 'read_only', // wrongly downgraded by the bug
            'is_active' => false,
        ]);

        $plan = $this->makePlan(['grace_days' => 14]);
        $affected = $this->makeSubscription([
            'plan' => $plan,
            'shop_id' => $shop->id,
            'status' => 'read_only',
            'starts_at' => now()->subDays(10)->toDateString(),
            'ends_at' => now()->subDays(3)->toDateString(), // ≈ starts + 7d
            'grace_ends_at' => now()->addDays(4)->toDateString(),
            'billing_cycle' => 'yearly',
            'price_paid' => 19999,
        ]);

        $this->artisan('subscription:repair-trial-terms --commit')
            ->assertExitCode(0);

        $affected->refresh();
        // Corrected to full year → active (well within the year).
        $this->assertSame('active', $affected->status);
        $this->assertSame(
            Carbon::parse($affected->starts_at)->addYear()->toDateString(),
            Carbon::parse($affected->ends_at)->toDateString()
        );
        $this->assertSame(
            Carbon::parse($affected->ends_at)->addDays(14)->toDateString(),
            Carbon::parse($affected->grace_ends_at)->toDateString()
        );

        $this->assertDatabaseHas('subscription_events', [
            'shop_subscription_id' => $affected->id,
            'event_type' => 'subscription.repaired',
        ]);

        // Shop access restored.
        $shop->refresh();
        $this->assertSame('active', $shop->access_mode);
        $this->assertTrue((bool) $shop->is_active);
    }
}
