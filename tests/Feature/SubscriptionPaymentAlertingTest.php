<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Jobs\SendOpsAlertEmail;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\User;
use App\Services\PlatformSubscriptionAlerts;
use App\Services\SubscriptionPaymentService;
use App\Services\SubscriptionWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * PART 6/7 — payment OUTCOME classification, webhook HTTP-status mapping,
 * Super-Admin unresolved-payment visibility, and the closed set of ops-email
 * alerts.
 *
 * Emails are asserted through Bus::fake([SendOpsAlertEmail::class]) because the
 * alerting path dispatches ONE queued job per event (Mail::raw runs only inside
 * that job, and Mail::fake does NOT capture Mail::raw). Faking the job lets us
 * count dispatches and inspect the human-readable subject/body without sending.
 */
class SubscriptionPaymentAlertingTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    private function ownerWithShop(): array
    {
        $shop = $this->createShop('retailer');
        $role = $this->createOwnerRole($shop->id);
        $owner = $this->createOwnerUser($shop, $role);

        return [$owner, $shop];
    }

    private function fakeOrder(int $userId, int $amountPaise = 99900, string $id = 'order_TEST', string $currency = 'INR'): object
    {
        return (object) [
            'id' => $id,
            'amount' => $amountPaise,
            'currency' => $currency,
            'notes' => ['user_id' => $userId, 'plan_id' => 0, 'billing_cycle' => 'monthly'],
        ];
    }

    private function bindFakeService(Plan $plan, object $order, string $cycle = 'monthly'): SubscriptionPaymentService
    {
        $svc = Mockery::mock(SubscriptionPaymentService::class)->makePartial();
        $svc->shouldReceive('fetchAndValidateOrder')
            ->andReturn(['order' => $order, 'plan' => $plan, 'billing_cycle' => $cycle]);
        $svc->shouldReceive('verifyPaymentCaptured')->andReturnNull();

        $this->app->instance(SubscriptionPaymentService::class, $svc);

        return $svc;
    }

    /** A webhook service whose signature check always passes (no real crypto). */
    private function bindTrustedWebhook(): void
    {
        $webhook = Mockery::mock(SubscriptionWebhookService::class)->makePartial();
        $webhook->shouldReceive('verifySignature')->andReturnNull();
        $this->app->instance(SubscriptionWebhookService::class, $webhook);
    }

    private function capturedPayload(string $paymentId, ?string $orderId): array
    {
        $entity = ['id' => $paymentId];
        if ($orderId !== null) {
            $entity['order_id'] = $orderId;
        }

        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => $entity]]];
    }

    // ── Outcome classification (Part 1) ─────────────────────────────────────

    public function test_transient_failure_records_transient_then_recovers_and_resolves(): void
    {
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();
        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        // No platform super admin yet → createSubscription throws
        // "Platform configuration incomplete." → TRANSIENT (safe to retry).
        $first = $svc->applyCapturedPayment('order_TEST', 'pay_T');
        $this->assertSame(SubscriptionPaymentService::OUTCOME_TRANSIENT, $first['outcome']);
        $this->assertNull($first['subscription']);

        $event = SubscriptionEvent::where('event_type', 'payment.unresolved')
            ->where('after->payment_id', 'pay_T')->firstOrFail();
        $this->assertTrue((bool) ($event->after['transient'] ?? false), 'recorded as transient');
        $this->assertNull($event->after['resolved_at'] ?? null, 'still open');

        // Fix the platform config → the very same payment now applies and the
        // open record flips to resolved WITHOUT being deleted.
        $this->createPlatformAdmin();
        $second = $svc->applyCapturedPayment('order_TEST', 'pay_T');
        $this->assertSame(SubscriptionPaymentService::OUTCOME_APPLIED, $second['outcome']);
        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_T')->count());

        $event->refresh();
        $this->assertNotNull($event->after['resolved_at'] ?? null, 'resolved, history preserved');
    }

    public function test_currency_mismatch_is_permanent(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        // Foreign currency on the order → tampered/foreign order, never appliable.
        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id, 99900, 'order_TEST', 'USD'));

        $result = $svc->applyCapturedPayment('order_TEST', 'pay_USD');

        $this->assertSame(SubscriptionPaymentService::OUTCOME_PERMANENT, $result['outcome']);
        $this->assertSame(0, ShopSubscription::where('razorpay_payment_id', 'pay_USD')->count());
        $this->assertDatabaseHas('subscription_events', ['event_type' => 'payment.unresolved']);
    }

    public function test_inactive_plan_is_permanent(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        $plan->forceFill(['is_active' => false])->save();
        [$owner] = $this->ownerWithShop();

        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        $result = $svc->applyCapturedPayment('order_TEST', 'pay_DEADPLAN');

        $this->assertSame(SubscriptionPaymentService::OUTCOME_PERMANENT, $result['outcome']);
        $this->assertSame(0, ShopSubscription::where('razorpay_payment_id', 'pay_DEADPLAN')->count());
    }

    // ── Webhook HTTP-status mapping (Part 1) ────────────────────────────────

    public function test_webhook_applied_payment_returns_200(): void
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        $this->bindFakeService($plan, $this->fakeOrder($owner->id));
        $this->bindTrustedWebhook();

        $this->postJson('/subscription/payment/webhook',
            $this->capturedPayload('pay_OK', 'order_TEST'),
            ['X-Razorpay-Signature' => 'stubbed']
        )->assertStatus(200)->assertJson(['status' => 'ok']);
    }

    public function test_webhook_transient_failure_returns_500_for_retry(): void
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);
        // No platform admin → createSubscription throws → transient → 500.
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        $this->bindFakeService($plan, $this->fakeOrder($owner->id));
        $this->bindTrustedWebhook();

        $this->postJson('/subscription/payment/webhook',
            $this->capturedPayload('pay_5XX', 'order_TEST'),
            ['X-Razorpay-Signature' => 'stubbed']
        )->assertStatus(500)->assertJson(['status' => 'retry']);

        $this->assertSame(0, ShopSubscription::where('razorpay_payment_id', 'pay_5XX')->count());
    }

    public function test_webhook_permanent_failure_returns_422(): void
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        // Currency mismatch → permanent → 422, Razorpay must stop retrying.
        $this->bindFakeService($plan, $this->fakeOrder($owner->id, 99900, 'order_TEST', 'USD'));
        $this->bindTrustedWebhook();

        $this->postJson('/subscription/payment/webhook',
            $this->capturedPayload('pay_422', 'order_TEST'),
            ['X-Razorpay-Signature' => 'stubbed']
        )->assertStatus(422)->assertJson(['status' => 'not_applied']);

        $this->assertDatabaseHas('subscription_events', ['event_type' => 'payment.unresolved']);
    }

    // ── Super-Admin visibility (Part 4) ─────────────────────────────────────

    public function test_super_admin_sees_unresolved_payment(): void
    {
        $admin = $this->createPlatformAdmin();
        // admin.mfa redirects any admin with a null email_verified_at to the
        // verify-email screen; a fully-trusted admin is verified.
        $admin->forceFill(['email_verified_at' => now()])->save();
        app(SubscriptionPaymentService::class)
            ->recordUnresolvedPayment('order_VIS', 'pay_VISIBLE', 'permanent: currency mismatch.', false);

        $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->get(route('admin.unresolved-payments.index'))
            ->assertOk()
            ->assertSee('pay_VISIBLE');
    }

    // ── Ops-email alerts — exact scope (Part 5) ─────────────────────────────

    public function test_shop_created_sends_exactly_one_alert(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        [$owner, $shop] = $this->ownerWithShop();

        app(PlatformSubscriptionAlerts::class)->shopCreated($shop);

        Bus::assertDispatchedTimes(SendOpsAlertEmail::class, 1);
        Bus::assertDispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'New shop created'));
    }

    public function test_applied_payment_sends_exactly_one_alert_and_no_duplicate_on_replay(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        // Apply the same payment twice (callback + webhook replay).
        $svc->applyCapturedPayment('order_TEST', 'pay_ONE');
        $svc->applyCapturedPayment('order_TEST', 'pay_ONE');

        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_ONE')->count());
        // Exactly ONE payment-applied email despite the duplicate delivery.
        Bus::assertDispatchedTimes(SendOpsAlertEmail::class, 1);
        Bus::assertDispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Payment applied'));
    }

    public function test_successful_reconciliation_sends_exactly_one_reconciled_alert_not_payment_applied(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();
        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        // First: no admin → transient unresolved → "retrying" alert.
        $svc->applyCapturedPayment('order_TEST', 'pay_RCX');
        Bus::assertDispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'unresolved'));

        // Then: fixed → reconciled. Exactly ONE reconciliation-success alert and
        // NOT a separate "Payment applied" — a reconciled payment must never emit
        // two success emails.
        $this->createPlatformAdmin();
        $svc->applyCapturedPayment('order_TEST', 'pay_RCX');

        Bus::assertDispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'reconciled'));
        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'reconciled')));
        Bus::assertNotDispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Payment applied'));
    }

    public function test_duplicate_reconciliation_sends_no_additional_alert(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();
        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        // Transient first, then reconcile, then a duplicate reconcile delivery.
        $svc->applyCapturedPayment('order_TEST', 'pay_RDUP');
        $this->createPlatformAdmin();
        $svc->applyCapturedPayment('order_TEST', 'pay_RDUP');
        $svc->applyCapturedPayment('order_TEST', 'pay_RDUP');

        // The unique constraint returns the existing row on replay WITHOUT
        // re-firing, so still exactly one reconciled alert.
        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'reconciled')));
    }

    public function test_no_alert_on_ordinary_subscription_state_change(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner, $shop] = $this->ownerWithShop();
        $current = $this->createSubscription($shop->id, $admin, $plan);

        // A renewal is an ordinary state change — NOT a captured-payment event
        // that alerting covers. It must emit no ops email.
        app(SubscriptionPaymentService::class)
            ->renewSubscription($current, 'monthly', 999.0, 'pay_RENEW', 'order_RENEW');

        Bus::assertNotDispatched(SendOpsAlertEmail::class);
    }

    public function test_failed_apply_sends_no_success_email(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();
        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id, 50000)); // wrong amount

        $svc->applyCapturedPayment('order_TEST', 'pay_FAIL');

        // No subscription, therefore NO "Payment applied" email.
        Bus::assertNotDispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Payment applied'));
    }

    public function test_applied_payment_alert_carries_no_secrets(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();
        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        $svc->applyCapturedPayment('order_TEST', 'pay_CLEAN');

        Bus::assertDispatched(SendOpsAlertEmail::class, function (SendOpsAlertEmail $job) {
            $blob = strtolower($job->subject . ' ' . $job->body);
            foreach (['signature', 'secret', 'token', 'key_id', 'key_secret', 'razorpay_signature'] as $needle) {
                $this->assertStringNotContainsString($needle, $blob, "alert leaked '{$needle}'");
            }
            return true;
        });
    }

    public function test_alert_delivery_is_decoupled_from_the_applied_payment(): void
    {
        // The alert job is pinned to connection=database/queue=ops-alerts, so it
        // NEVER runs inline at the dispatch site (even though the app default is
        // sync). Mail::raw therefore happens later in a dedicated worker, fully
        // decoupled from the payment transaction: a broken SMTP hop can never
        // roll back the committed subscription. Prove raw is not called inline.
        config(['platform.alert_email' => 'ops@jewelflows.test']);
        Mail::shouldReceive('raw')->never();

        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        // A shopless owner → no platform invoice email path in this flow.
        $owner = User::factory()->create(['shop_id' => null]);
        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        $result = $svc->applyCapturedPayment('order_TEST', 'pay_MAILDOWN');

        $this->assertSame(SubscriptionPaymentService::OUTCOME_APPLIED, $result['outcome']);
        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_MAILDOWN')->count(),
            'the deferred alert must never roll back the committed payment');
    }

    // ── Queue isolation (Part: infra design correction) ─────────────────────

    public function test_ops_alert_job_is_pinned_to_isolated_connection_and_queue(): void
    {
        // Structural guarantee: the job carries its OWN connection + queue so that
        // enabling subscription alerting never changes how the 11 unrelated queued
        // workflows run under QUEUE_CONNECTION=sync. A worker draining ONLY
        // database/ops-alerts picks this up; a worker on `default` never will.
        $job = new SendOpsAlertEmail('subj', 'body', 'evt');

        $this->assertSame('database', $job->connection, 'must not ride the sync default');
        $this->assertSame('ops-alerts', $job->queue, 'must be isolated on its own queue');
    }

    public function test_dispatched_ops_alert_is_routed_to_the_isolated_queue(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        [$owner, $shop] = $this->ownerWithShop();

        app(PlatformSubscriptionAlerts::class)->shopCreated($shop);

        Bus::assertDispatched(SendOpsAlertEmail::class, function (SendOpsAlertEmail $job) {
            return $job->connection === 'database' && $job->queue === 'ops-alerts';
        });
    }

    public function test_ops_alert_uniqueId_is_stable_per_business_event(): void
    {
        // Duplicate business-event paths (double callback/webhook/reconcile)
        // collapse to one queued job because uniqueId() keys on the semantic
        // eventKey, not on content. A keyless job still can't double-enqueue.
        $a = new SendOpsAlertEmail('s', 'b', 'payment-applied:pay_X');
        $b = new SendOpsAlertEmail('s2', 'b2', 'payment-applied:pay_X');
        $this->assertSame($a->uniqueId(), $b->uniqueId(), 'same event → same lock key');

        $c = new SendOpsAlertEmail('s', 'b', 'payment-applied:pay_Y');
        $this->assertNotSame($a->uniqueId(), $c->uniqueId(), 'different event → different key');

        $keyless = new SendOpsAlertEmail('subj', 'body');
        $this->assertNotSame('', $keyless->uniqueId(), 'keyless falls back to content hash');
    }

    public function test_payment_failed_webhook_sends_no_ops_alert(): void
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);
        Bus::fake([SendOpsAlertEmail::class]);
        $this->bindTrustedWebhook();

        $this->postJson('/subscription/payment/webhook',
            ['event' => 'payment.failed',
             'payload' => ['payment' => ['entity' => ['order_id' => 'order_F', 'error_description' => 'declined']]]],
            ['X-Razorpay-Signature' => 'stubbed']
        )->assertStatus(200);

        // payment.failed only writes a SubscriptionEvent — the closed alert set
        // never emits ops email for a failed payment.
        Bus::assertNotDispatched(SendOpsAlertEmail::class);
    }

    public function test_full_refund_webhook_sends_exactly_one_refund_alert_and_dedupes_on_replay(): void
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);
        Bus::fake([SendOpsAlertEmail::class]);
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner, $shop] = $this->ownerWithShop();

        $sub = $this->createSubscription($shop->id, $admin, $plan);
        $sub->forceFill(['razorpay_payment_id' => 'pay_REF', 'price_paid' => 999.0])->save();

        $this->bindTrustedWebhook();

        $payload = ['event' => 'refund.created',
            'payload' => ['refund' => ['entity' => [
                'id' => 'rfnd_1', 'payment_id' => 'pay_REF', 'amount' => 99900,
            ]]]];

        $this->postJson('/subscription/payment/webhook', $payload, ['X-Razorpay-Signature' => 'stubbed'])
            ->assertStatus(200);
        // Duplicate delivery: the subscription is now cancelled, so the
        // status!=cancelled filter finds nothing and fires no second alert.
        $this->postJson('/subscription/payment/webhook', $payload, ['X-Razorpay-Signature' => 'stubbed'])
            ->assertStatus(200);

        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Refund processed')));
        $this->assertSame('cancelled', $sub->fresh()->status);
    }

    public function test_callback_then_webhook_sends_one_success_alert(): void
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);
        Bus::fake([SendOpsAlertEmail::class]);
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();
        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));
        $this->bindTrustedWebhook();

        // Callback applies first…
        $svc->applyCapturedPayment('order_TEST', 'pay_SEQ');
        // …then the webhook races in for the same payment.
        $this->postJson('/subscription/payment/webhook',
            $this->capturedPayload('pay_SEQ', 'order_TEST'),
            ['X-Razorpay-Signature' => 'stubbed']
        )->assertStatus(200);

        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_SEQ')->count());
        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Payment applied')));
    }
}
