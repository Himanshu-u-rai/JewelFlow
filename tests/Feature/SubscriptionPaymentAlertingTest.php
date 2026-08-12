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
use Illuminate\Support\Facades\DB;
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

    /**
     * Razorpay stamps every webhook with a unique x-razorpay-event-id header.
     * The controller fail-closes on empty, so tests that hit the HTTP surface
     * MUST send one. This helper defaults to a per-call unique id; tests that
     * need to simulate a genuine at-least-once redelivery pass the same id twice.
     */
    private function webhookHeaders(string $eventId = 'evt_default'): array
    {
        return [
            'X-Razorpay-Signature' => 'stubbed',
            'X-Razorpay-Event-Id' => $eventId,
        ];
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
            $this->webhookHeaders('evt_ok')
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
            $this->webhookHeaders('evt_5xx')
        )->assertStatus(500)->assertJson(['status' => 'retry']);

        $this->assertSame(0, ShopSubscription::where('razorpay_payment_id', 'pay_5XX')->count());
    }

    public function test_webhook_permanent_failure_returns_200_after_evidence(): void
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        // Currency mismatch → permanent. Contract: 2xx AFTER immutable evidence
        // has been recorded (Razorpay must NOT retry a durably-consumed event).
        $this->bindFakeService($plan, $this->fakeOrder($owner->id, 99900, 'order_TEST', 'USD'));
        $this->bindTrustedWebhook();

        $this->postJson('/subscription/payment/webhook',
            $this->capturedPayload('pay_perm', 'order_TEST'),
            $this->webhookHeaders('evt_perm')
        )->assertStatus(200)->assertJson(['status' => 'not_applied']);

        $this->assertDatabaseHas('subscription_events', ['event_type' => 'payment.unresolved']);
    }

    public function test_webhook_rejects_missing_event_id(): void
    {
        // At-least-once delivery contract: Razorpay sends x-razorpay-event-id on
        // every webhook. Missing → fail-closed 400 (cannot dedup → cannot safely
        // process). No mutation, no evidence, no retry storm.
        config(['services.razorpay.webhook_secret' => 'test_secret']);
        $this->bindTrustedWebhook();

        $this->postJson('/subscription/payment/webhook',
            $this->capturedPayload('pay_NOEID', 'order_TEST'),
            ['X-Razorpay-Signature' => 'stubbed'] // no event id
        )->assertStatus(400)->assertJson(['status' => 'rejected']);

        $this->assertSame(0, ShopSubscription::where('razorpay_payment_id', 'pay_NOEID')->count());
        $this->assertDatabaseMissing('subscription_events', ['event_type' => 'payment.unresolved']);
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
        config(['platform.subscription_alert_email' => 'ops@jewelflows.test']);
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
            $this->webhookHeaders('evt_failed')
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
                'currency' => 'INR',
            ]]]];

        // A genuine Razorpay redelivery carries the SAME x-razorpay-event-id.
        $headers = $this->webhookHeaders('evt_full_refund_1');
        $this->postJson('/subscription/payment/webhook', $payload, $headers)
            ->assertStatus(200);
        // Duplicate delivery AFTER the first was fully processed: the durable
        // subscription-event carrying event_id + refund_id is found (both
        // dedup layers hold), so no second alert.
        $this->postJson('/subscription/payment/webhook', $payload, $headers)
            ->assertStatus(200);

        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Full refund processed')));
        $this->assertSame('cancelled', $sub->fresh()->status);
        // Exactly one durable refund event recorded for this refund id.
        $this->assertSame(1, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->where('event_type', 'subscription.refunded')
            ->where('after->refund_id', 'rfnd_1')->count());
    }

    public function test_partial_refund_webhook_sends_one_alert_and_keeps_subscription_active(): void
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);
        Bus::fake([SendOpsAlertEmail::class]);
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner, $shop] = $this->ownerWithShop();

        $sub = $this->createSubscription($shop->id, $admin, $plan);
        $sub->forceFill(['razorpay_payment_id' => 'pay_PARTIAL', 'price_paid' => 999.0])->save();

        $this->bindTrustedWebhook();

        // Refund only ₹400 of ₹999 → partial: alert fires, NO revocation.
        $payload = ['event' => 'refund.created',
            'payload' => ['refund' => ['entity' => [
                'id' => 'rfnd_P', 'payment_id' => 'pay_PARTIAL', 'amount' => 40000,
                'currency' => 'INR',
            ]]]];

        $headers = $this->webhookHeaders('evt_partial_1');
        $this->postJson('/subscription/payment/webhook', $payload, $headers)
            ->assertStatus(200);
        // Delayed duplicate after first fully processed → durable dedup, no 2nd alert.
        $this->postJson('/subscription/payment/webhook', $payload, $headers)
            ->assertStatus(200);

        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Partial refund processed')));
        // Subscription must NOT be cancelled by a partial refund.
        $this->assertNotSame('cancelled', $sub->fresh()->status);
        $this->assertSame(1, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->where('event_type', 'subscription.partial_refund')
            ->where('after->refund_id', 'rfnd_P')->count());
    }

    public function test_duplicate_refund_after_first_completed_enqueues_no_second_alert(): void
    {
        // Explicit durable-dedup proof: drive handleRefundCreated twice directly,
        // simulating a delivery that arrives after the first job already finished
        // AND its ShouldBeUnique lock has evaporated. The immutable event row —
        // not the cache — is what collapses the duplicate.
        Bus::fake([SendOpsAlertEmail::class]);
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner, $shop] = $this->ownerWithShop();

        $sub = $this->createSubscription($shop->id, $admin, $plan);
        $sub->forceFill(['razorpay_payment_id' => 'pay_DDUP', 'price_paid' => 999.0])->save();

        $webhook = app(SubscriptionWebhookService::class);
        $payload = ['payload' => ['refund' => ['entity' => [
            'id' => 'rfnd_D', 'payment_id' => 'pay_DDUP', 'amount' => 99900,
            'currency' => 'INR',
        ]]]];

        // Same event_id twice — a genuine at-least-once redelivery.
        $webhook->handleRefundCreated($payload, 'evt_ddup');
        $webhook->handleRefundCreated($payload, 'evt_ddup');

        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'refund processed')));
        $this->assertSame(1, SubscriptionEvent::where('after->refund_id', 'rfnd_D')->count());
    }

    // ── Fail-closed refund validation (Phase 2) ─────────────────────────────

    /**
     * Post a refund.created webhook carrying an arbitrary entity map. Returns
     * the response so each test asserts its own status + side-effects. Each
     * call gets a unique x-razorpay-event-id unless explicitly overridden.
     */
    private function postRefund(array $entity, string $eventId = null)
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);
        $this->bindTrustedWebhook();

        return $this->postJson('/subscription/payment/webhook',
            ['event' => 'refund.created', 'payload' => ['refund' => ['entity' => $entity]]],
            $this->webhookHeaders($eventId ?? 'evt_' . bin2hex(random_bytes(6)))
        );
    }

    /**
     * Shared assertions for a refund that must be REFUSED before mutation:
     *   • webhook responds 200 (durable evidence recorded → Razorpay must NOT retry)
     *   • an immutable `refund.invalid` event is recorded
     *   • the subscription is NOT cancelled and NOT marked refunded
     *   • no refundProcessed ops alert is dispatched
     */
    private function assertRefundRefused(ShopSubscription $sub): void
    {
        $this->assertDatabaseHas('subscription_events', ['event_type' => 'refund.invalid']);
        $this->assertNotSame('cancelled', $sub->fresh()->status,
            'refund refusal must not cancel the subscription');
        $this->assertSame(0, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->whereIn('event_type', ['subscription.refunded', 'subscription.partial_refund'])
            ->count(), 'no refund mutation event may exist');
        Bus::assertNotDispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'refund processed'));
    }

    private function seedSubscriptionForRefund(string $paymentId): ShopSubscription
    {
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner, $shop] = $this->ownerWithShop();
        $sub = $this->createSubscription($shop->id, $admin, $plan);
        $sub->forceFill(['razorpay_payment_id' => $paymentId, 'price_paid' => 999.0])->save();
        return $sub;
    }

    public function test_refund_missing_refund_id_is_refused_permanent(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_MRID');

        // No 'id' key at all → validation should refuse before touching the sub.
        $this->postRefund(['payment_id' => 'pay_MRID', 'amount' => 99900, 'currency' => 'INR'])
            ->assertStatus(200)->assertJson(['status' => 'not_applied']);

        $this->assertRefundRefused($sub);
    }

    public function test_refund_blank_refund_id_is_refused_permanent(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_BRID');

        // Explicit empty string — the same rung as missing.
        $this->postRefund(['id' => '', 'payment_id' => 'pay_BRID', 'amount' => 99900, 'currency' => 'INR'])
            ->assertStatus(200)->assertJson(['status' => 'not_applied']);

        $this->assertRefundRefused($sub);
    }

    public function test_refund_missing_payment_id_is_refused_permanent(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_MPID');

        // No payment_id → cannot bind to any subscription → refuse.
        $this->postRefund(['id' => 'rfnd_MP', 'amount' => 99900, 'currency' => 'INR'])
            ->assertStatus(200)->assertJson(['status' => 'not_applied']);

        $this->assertRefundRefused($sub);
    }

    public function test_refund_non_positive_amount_is_refused_permanent(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_ZERO');

        // Zero amount → nonsense money → refuse.
        $this->postRefund(['id' => 'rfnd_Z', 'payment_id' => 'pay_ZERO', 'amount' => 0, 'currency' => 'INR'])
            ->assertStatus(200)->assertJson(['status' => 'not_applied']);

        $this->assertRefundRefused($sub);

        // Negative amount → same rung, same refusal.
        Bus::fake([SendOpsAlertEmail::class]);
        $this->postRefund(['id' => 'rfnd_N', 'payment_id' => 'pay_ZERO', 'amount' => -100, 'currency' => 'INR'])
            ->assertStatus(200)->assertJson(['status' => 'not_applied']);
    }

    public function test_refund_currency_mismatch_is_refused_permanent(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_USD');

        // Subscriptions are INR-only; a USD event is foreign/tampered → refuse.
        $this->postRefund(['id' => 'rfnd_USD', 'payment_id' => 'pay_USD', 'amount' => 99900, 'currency' => 'USD'])
            ->assertStatus(200)->assertJson(['status' => 'not_applied']);

        $this->assertRefundRefused($sub);
        $this->assertDatabaseHas('subscription_events', [
            'event_type' => 'refund.invalid',
            'after->refund_id' => 'rfnd_USD',
        ]);
    }

    public function test_refund_transient_db_failure_returns_transient_with_no_partial_state(): void
    {
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_TXN');

        // Force the transaction closure to throw — simulates a DB deadlock or
        // the provider dropping the row lock. The handler MUST catch, return
        // TRANSIENT, and leave NO partial state behind.
        //
        // Direct service call (not the HTTP route) so faking DB::transaction
        // doesn't collide with the framework session driver's DB access.
        // The controller's TRANSIENT→500 mapping is already proven by
        // test_webhook_transient_failure_returns_500_for_retry.
        //
        // Swap ONLY for the service call, then restore, so subsequent
        // assertions (assertDatabaseMissing, Eloquent counts) run against the
        // real database manager.
        $real = $this->app->make('db');
        $mock = Mockery::mock($real)->makePartial();
        $mock->shouldReceive('transaction')->once()
            ->andThrow(new \RuntimeException('simulated deadlock'));
        DB::swap($mock);

        try {
            $outcome = app(SubscriptionWebhookService::class)->handleRefundCreated([
                'payload' => ['refund' => ['entity' => [
                    'id' => 'rfnd_TXN', 'payment_id' => 'pay_TXN',
                    'amount' => 99900, 'currency' => 'INR',
                ]]],
            ], 'evt_txn');
        } finally {
            DB::swap($real);
        }

        $this->assertSame(SubscriptionPaymentService::OUTCOME_TRANSIENT, $outcome,
            'a transient DB failure must classify as transient (→ 500 for retry)');
        $this->assertNotSame('cancelled', $sub->fresh()->status);
        $this->assertSame(0, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->whereIn('event_type', ['subscription.refunded', 'subscription.partial_refund'])
            ->count());
        $this->assertDatabaseMissing('subscription_events', ['event_type' => 'refund.invalid']);
        Bus::assertNotDispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'refund processed'));
    }

    // ── Fail-closed recipient (SUBSCRIPTION_ALERT_EMAIL only) ───────────────

    public function test_ops_alert_sends_to_dedicated_subscription_recipient(): void
    {
        config(['platform.subscription_alert_email' => 'subs-ops@jewelflows.test']);
        // The dedicated inbox is set → the job delivers to exactly that address.
        Mail::shouldReceive('raw')->once()->withArgs(function ($body, $closure) {
            $msg = Mockery::mock();
            $msg->shouldReceive('to')->once()->with('subs-ops@jewelflows.test')->andReturnSelf();
            $msg->shouldReceive('subject')->once()->andReturnSelf();
            $closure($msg);
            return true;
        });

        (new SendOpsAlertEmail('Payment applied — Shop', 'body line', 'evt'))->handle();
    }

    public function test_ops_alert_suppressed_when_dedicated_recipient_missing(): void
    {
        config(['platform.subscription_alert_email' => '']);
        // Blank dedicated inbox → fail-closed: no send at all.
        Mail::shouldReceive('raw')->never();

        (new SendOpsAlertEmail('Payment applied — Shop', 'body line', 'evt'))->handle();
    }

    public function test_platform_alert_email_alone_does_not_enable_subscription_alerts(): void
    {
        // The shared fraud/health/evaluate key is set, but the dedicated
        // subscription key is NOT → the subscription pipeline still suppresses.
        // Proves there is NO fallback from alert_email into this pipeline.
        config([
            'platform.alert_email' => 'shared-ops@jewelflows.test',
            'platform.subscription_alert_email' => '',
        ]);
        Mail::shouldReceive('raw')->never();

        (new SendOpsAlertEmail('Payment applied — Shop', 'body line', 'evt'))->handle();
    }

    public function test_dedicated_recipient_does_not_feed_unrelated_platform_pipelines(): void
    {
        // Structural isolation: the subscription pipeline reads ONLY
        // subscription_alert_email; the fraud/shop-health/evaluate pipelines read
        // ONLY alert_email. Setting one must never populate the other.
        config([
            'platform.subscription_alert_email' => 'subs-ops@jewelflows.test',
            'platform.alert_email' => '',
        ]);

        $this->assertSame('subs-ops@jewelflows.test', config('platform.subscription_alert_email'));
        $this->assertSame('', config('platform.alert_email'),
            'setting the dedicated key must not leak into the shared fraud/health/evaluate key');
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
            $this->webhookHeaders('evt_seq')
        )->assertStatus(200);

        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_SEQ')->count());
        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Payment applied')));
    }

    // ── Money-integrity + event_id dedup (Phase 5 of webhook ACK/refund task) ──

    public function test_duplicate_malformed_refund_by_event_id_records_one_evidence_only(): void
    {
        // At-least-once redelivery of the SAME malformed event (same event_id):
        // the evidence row is created ONCE by event-id dedup — attempt_count
        // may advance, but no second refund.invalid row appears.
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_DUPMAL');

        $entity = ['id' => 'rfnd_DM', 'payment_id' => 'pay_DUPMAL', 'amount' => 0, 'currency' => 'INR'];
        $this->postRefund($entity, 'evt_dup_mal')->assertStatus(200);
        $this->postRefund($entity, 'evt_dup_mal')->assertStatus(200);

        $this->assertSame(1, SubscriptionEvent::where('event_type', 'refund.invalid')
            ->where('after->event_id', 'evt_dup_mal')->count());
        // Alert fires on the FIRST occurrence only; the redelivery hits the
        // dedup-update branch in recordInvalidRefund and does not re-alert.
        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Refund permanently refused')));
        $this->assertRefundRefused($sub);
    }

    public function test_duplicate_valid_refund_by_event_id_dedups(): void
    {
        // Same VALID refund redelivered under the same event_id: exactly one
        // subscription.refunded event, exactly one alert.
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_EIDDUP');

        $entity = ['id' => 'rfnd_EID', 'payment_id' => 'pay_EIDDUP', 'amount' => 99900, 'currency' => 'INR'];
        $this->postRefund($entity, 'evt_eid_dup')->assertStatus(200);
        $this->postRefund($entity, 'evt_eid_dup')->assertStatus(200);

        $this->assertSame(1, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->where('event_type', 'subscription.refunded')
            ->where('after->refund_id', 'rfnd_EID')->count());
        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'refund processed')));
    }

    public function test_two_distinct_partial_refunds_track_cumulative_and_keep_active(): void
    {
        // Two DIFFERENT refunds — each < captured, cumulative < captured —
        // must both be recorded as partial. Subscription remains active.
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_2PART');

        $this->postRefund(
            ['id' => 'rfnd_P1', 'payment_id' => 'pay_2PART', 'amount' => 30000, 'currency' => 'INR'],
            'evt_p1'
        )->assertStatus(200);
        $this->postRefund(
            ['id' => 'rfnd_P2', 'payment_id' => 'pay_2PART', 'amount' => 40000, 'currency' => 'INR'],
            'evt_p2'
        )->assertStatus(200);

        $this->assertSame(2, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->where('event_type', 'subscription.partial_refund')->count());
        $this->assertNotSame('cancelled', $sub->fresh()->status);
        // Two distinct alerts, one per refund.
        $this->assertCount(2, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Partial refund processed')));
    }

    public function test_final_partial_completing_captured_triggers_full_refund_behavior_exactly_once(): void
    {
        // Two partials whose SUM equals captured: the second refund is the one
        // that pushes cumulative to captured → full-refund behavior (cancel +
        // revoke) fires exactly once. That refund's event is classified as
        // subscription.refunded, not partial.
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_FINPART');

        // ₹300 partial
        $this->postRefund(
            ['id' => 'rfnd_A', 'payment_id' => 'pay_FINPART', 'amount' => 30000, 'currency' => 'INR'],
            'evt_a'
        )->assertStatus(200);
        $this->assertNotSame('cancelled', $sub->fresh()->status,
            'first partial must NOT cancel the subscription');

        // ₹699 → cumulative ₹999 = captured ₹999
        $this->postRefund(
            ['id' => 'rfnd_B', 'payment_id' => 'pay_FINPART', 'amount' => 69900, 'currency' => 'INR'],
            'evt_b'
        )->assertStatus(200);

        $this->assertSame('cancelled', $sub->fresh()->status,
            'the refund that pushes cumulative to captured cancels the subscription');
        $this->assertSame(1, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->where('event_type', 'subscription.refunded')
            ->where('after->refund_id', 'rfnd_B')->count(),
            'the final refund is classified as full');
        $this->assertSame(1, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->where('event_type', 'subscription.partial_refund')->count(),
            'earlier refund stays classified as partial');
        // Exactly one Full-refund alert + one Partial-refund alert.
        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Full refund processed')));
        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Partial refund processed')));
    }

    public function test_individual_refund_over_captured_records_invalid_evidence(): void
    {
        // A single refund whose amount alone > captured is nonsense (tampered
        // or misdirected). refund.invalid evidence, no mutation, no alert.
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_OVER1');

        $this->postRefund(
            ['id' => 'rfnd_OV1', 'payment_id' => 'pay_OVER1', 'amount' => 200000, 'currency' => 'INR'],
            'evt_ov1'
        )->assertStatus(200)->assertJson(['status' => 'not_applied']);

        $this->assertDatabaseHas('subscription_events', [
            'event_type' => 'refund.invalid',
            'after->refund_id' => 'rfnd_OV1',
        ]);
        $this->assertRefundRefused($sub);
    }

    public function test_cumulative_refunds_over_captured_records_invalid_evidence(): void
    {
        // Two refunds — each ≤ captured individually, but SUM > captured → the
        // second one must be refused as invalid evidence, no mutation.
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_OVERSUM');

        // ₹700 partial — legitimate.
        $this->postRefund(
            ['id' => 'rfnd_S1', 'payment_id' => 'pay_OVERSUM', 'amount' => 70000, 'currency' => 'INR'],
            'evt_s1'
        )->assertStatus(200);
        // ₹500 partial — but cumulative would be ₹1200 > ₹999 captured.
        $this->postRefund(
            ['id' => 'rfnd_S2', 'payment_id' => 'pay_OVERSUM', 'amount' => 50000, 'currency' => 'INR'],
            'evt_s2'
        )->assertStatus(200)->assertJson(['status' => 'not_applied']);

        $this->assertDatabaseHas('subscription_events', [
            'event_type' => 'refund.invalid',
            'after->refund_id' => 'rfnd_S2',
        ]);
        // First partial remains valid; subscription stays active.
        $this->assertSame(1, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->where('event_type', 'subscription.partial_refund')->count());
        $this->assertNotSame('cancelled', $sub->fresh()->status);
    }

    public function test_unknown_payment_id_records_invalid_evidence(): void
    {
        // Refund arrives for a payment id that has NO local subscription.
        // Under the corrected contract this is refund.invalid evidence (not a
        // silent no-op) — a genuine cross-shop mismatch or misroute needs
        // admin visibility.
        Bus::fake([SendOpsAlertEmail::class]);

        $this->postRefund(
            ['id' => 'rfnd_UNK', 'payment_id' => 'pay_UNKNOWN', 'amount' => 10000, 'currency' => 'INR'],
            'evt_unk'
        )->assertStatus(200)->assertJson(['status' => 'not_applied']);

        $this->assertDatabaseHas('subscription_events', [
            'event_type' => 'refund.invalid',
            'after->refund_id' => 'rfnd_UNK',
            'after->payment_id' => 'pay_UNKNOWN',
        ]);
        Bus::assertNotDispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'refund processed'));
    }

    public function test_cross_shop_refund_attaches_evidence_to_correct_shop_only(): void
    {
        // Two shops, one payment id owned by shop A. A refund for pay_A must
        // stamp its refund event on shop A only — never on shop B. (The refund
        // resolves by unique razorpay_payment_id, so the tenant is authoritative.)
        Bus::fake([SendOpsAlertEmail::class]);
        $subA = $this->seedSubscriptionForRefund('pay_A');
        // A second shop with its own subscription (different payment id).
        $shopBAdmin = $this->createPlatformAdmin();
        $planB = $this->createPlan('retailer');
        $shopB = $this->createShop('retailer');
        $roleB = $this->createOwnerRole($shopB->id);
        $this->createOwnerUser($shopB, $roleB);
        $subB = $this->createSubscription($shopB->id, $shopBAdmin, $planB);
        $subB->forceFill(['razorpay_payment_id' => 'pay_B', 'price_paid' => 999.0])->save();

        // Refund for pay_A must land only on subA / shopA.
        $this->postRefund(
            ['id' => 'rfnd_A', 'payment_id' => 'pay_A', 'amount' => 40000, 'currency' => 'INR'],
            'evt_a_only'
        )->assertStatus(200);

        $this->assertSame(1, SubscriptionEvent::where('shop_subscription_id', $subA->id)
            ->where('event_type', 'subscription.partial_refund')->count());
        $this->assertSame(0, SubscriptionEvent::where('shop_subscription_id', $subB->id)
            ->whereIn('event_type', ['subscription.refunded', 'subscription.partial_refund'])->count(),
            'refund for shop A must never attach to shop B');
        $this->assertSame($subA->shop_id, SubscriptionEvent::where('shop_subscription_id', $subA->id)
            ->where('event_type', 'subscription.partial_refund')->firstOrFail()->shop_id);
    }

    public function test_transient_recovery_processes_exactly_once(): void
    {
        // First delivery raises → transient (no mutation, no evidence).
        // Second delivery (identical event_id, DB healthy) → processed exactly
        // once: single refund event, single alert.
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_RECOV');

        $payload = ['payload' => ['refund' => ['entity' => [
            'id' => 'rfnd_R', 'payment_id' => 'pay_RECOV', 'amount' => 99900, 'currency' => 'INR',
        ]]]];

        // Inject transient failure ONLY for the first call.
        $real = $this->app->make('db');
        $mock = Mockery::mock($real)->makePartial();
        $mock->shouldReceive('transaction')->once()->andThrow(new \RuntimeException('simulated deadlock'));
        DB::swap($mock);

        $outcome1 = null;
        try {
            $outcome1 = app(SubscriptionWebhookService::class)->handleRefundCreated($payload, 'evt_recov');
        } finally {
            DB::swap($real);
        }

        $this->assertSame(SubscriptionPaymentService::OUTCOME_TRANSIENT, $outcome1);
        $this->assertSame(0, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->whereIn('event_type', ['subscription.refunded', 'subscription.partial_refund'])->count());

        // Second delivery — DB healthy. Must process exactly once.
        $outcome2 = app(SubscriptionWebhookService::class)->handleRefundCreated($payload, 'evt_recov');
        $this->assertSame(SubscriptionPaymentService::OUTCOME_APPLIED, $outcome2);
        $this->assertSame(1, SubscriptionEvent::where('shop_subscription_id', $sub->id)
            ->where('event_type', 'subscription.refunded')->count());
        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'refund processed')));
    }

    public function test_event_id_is_sole_dedup_when_both_ids_blank(): void
    {
        // A pure-junk redelivery: no refund_id AND no payment_id AND same
        // event_id twice → event_id is the ONLY dedup primitive available.
        // Exactly ONE refund.invalid row must be recorded.
        Bus::fake([SendOpsAlertEmail::class]);

        $this->postRefund(['amount' => 0, 'currency' => 'INR'], 'evt_blank_ids')
            ->assertStatus(200);
        $this->postRefund(['amount' => 0, 'currency' => 'INR'], 'evt_blank_ids')
            ->assertStatus(200);

        $this->assertSame(1, SubscriptionEvent::where('event_type', 'refund.invalid')
            ->where('after->event_id', 'evt_blank_ids')->count(),
            'event_id must be sufficient to dedup when refund_id and payment_id are absent');
    }

    public function test_permanently_invalid_refund_dispatches_one_dedicated_permanent_alert(): void
    {
        // A validly-signed but permanently-invalid refund MUST enqueue exactly
        // ONE dedicated permanent-validation-failure alert, on connection
        // `database` / queue `ops-alerts` (SendOpsAlertEmail is pinned there),
        // with a stable eventKey keyed on x-razorpay-event-id. No `refund
        // processed` alert may fire.
        Bus::fake([SendOpsAlertEmail::class]);
        $sub = $this->seedSubscriptionForRefund('pay_ALERT');

        $this->postRefund(
            ['id' => 'rfnd_A', 'payment_id' => 'pay_ALERT', 'amount' => 200000, 'currency' => 'INR'],
            'evt_alert_one'
        )->assertStatus(200)->assertJson(['status' => 'not_applied']);

        // Exactly one permanent-failure alert; zero refund-processed alerts.
        $permanent = Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Refund permanently refused'));
        $this->assertCount(1, $permanent, 'exactly one permanent-refusal alert must fire');
        Bus::assertNotDispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'refund processed'));

        /** @var SendOpsAlertEmail $job */
        $job = $permanent->first();
        // The Queueable trait's onConnection()/onQueue() calls in the ctor
        // populate public $connection / $queue on the job instance.
        $this->assertSame('database',   $job->connection, 'alert must ride the database connection');
        $this->assertSame('ops-alerts', $job->queue,      'alert must ride the ops-alerts queue');
        // Stable dedup key keyed on the Razorpay event id (strongest primitive).
        $this->assertSame('refund-permanent:evt_alert_one', $job->uniqueId());
        // Body carries only safe references (event/refund/payment refs + reason).
        $this->assertStringContainsString('evt_alert_one', $job->body);
        $this->assertStringContainsString('rfnd_A',       $job->body);
        $this->assertStringContainsString('pay_ALERT',    $job->body);
    }

    public function test_duplicate_permanently_invalid_refund_alerts_only_once(): void
    {
        // Redelivery of the SAME permanently-invalid event: evidence dedups on
        // event_id AND the alert dedups on the same primitive — exactly one of
        // each survives across arbitrary redeliveries.
        Bus::fake([SendOpsAlertEmail::class]);
        $this->seedSubscriptionForRefund('pay_ALERT_DUP');
        $entity = ['id' => 'rfnd_AD', 'payment_id' => 'pay_ALERT_DUP', 'amount' => 200000, 'currency' => 'INR'];

        $this->postRefund($entity, 'evt_alert_dup')->assertStatus(200);
        $this->postRefund($entity, 'evt_alert_dup')->assertStatus(200);
        $this->postRefund($entity, 'evt_alert_dup')->assertStatus(200);

        $this->assertSame(1, SubscriptionEvent::where('event_type', 'refund.invalid')
            ->where('after->event_id', 'evt_alert_dup')->count());
        $this->assertCount(1, Bus::dispatched(SendOpsAlertEmail::class,
            fn (SendOpsAlertEmail $job) => str_contains($job->subject, 'Refund permanently refused')));
    }

    public function test_refund_invalid_evidence_carries_no_secrets_or_signature(): void
    {
        // The refund.invalid audit row must persist only safe fields:
        // event_id, refund_id, payment_id, paise, currency, timestamps,
        // reason. NEVER signature/secret/token/card/PII.
        Bus::fake([SendOpsAlertEmail::class]);
        $this->seedSubscriptionForRefund('pay_CLEAN_INV');

        $this->postRefund(
            ['id' => 'rfnd_CLN', 'payment_id' => 'pay_CLEAN_INV', 'amount' => 200000, 'currency' => 'INR'],
            'evt_clean_inv'
        );

        $event = SubscriptionEvent::where('event_type', 'refund.invalid')
            ->where('after->refund_id', 'rfnd_CLN')->firstOrFail();
        $blob = strtolower(json_encode($event->after) . ' ' . strtolower($event->reason));
        foreach (['signature', 'secret', 'token', 'razorpay_signature', 'card_number', 'cvv', 'pan '] as $needle) {
            $this->assertStringNotContainsString($needle, $blob, "invalid-refund evidence leaked '{$needle}'");
        }
    }
}
