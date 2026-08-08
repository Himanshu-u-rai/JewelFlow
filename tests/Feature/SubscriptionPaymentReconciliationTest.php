<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use App\Services\SubscriptionWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * REVENUE SAFETY — "Razorpay PAID, JewelFlow NOT activated".
 *
 * Before this fix a subscription was created ONLY by the in-session browser
 * callback. If the browser closed / the callback network failed, the captured
 * payment left NO subscription and nothing self-healed. The webhook only ever
 * UPDATED an existing subscription and RETURNED when none was found.
 *
 * The fix routes the callback, the payment.captured webhook and the reconcile
 * command through ONE session-less, idempotent finalization path
 * (SubscriptionPaymentService::finalizeCapturedPayment) that re-resolves
 * user/plan/amount server-side from the Razorpay order and, when a payment
 * genuinely cannot be applied, records an immutable admin-visible mismatch
 * (SubscriptionEvent event_type=payment.unresolved).
 *
 * The two provider-touching seams (fetchAndValidateOrder, verifyPaymentCaptured)
 * are stubbed so NO real Razorpay request is made; every idempotency / anti-stack
 * / DB-constraint path runs for real.
 */
class SubscriptionPaymentReconciliationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    /** Owner of an active shop, whose id is what the order notes carry. */
    private function ownerWithShop(): array
    {
        $shop = $this->createShop('retailer');
        $role = $this->createOwnerRole($shop->id);
        $owner = $this->createOwnerUser($shop, $role);

        return [$owner, $shop];
    }

    /** A fake Razorpay order object — never touches the network. */
    private function fakeOrder(int $userId, int $amountPaise = 99900, string $id = 'order_TEST', string $currency = 'INR'): object
    {
        return (object) [
            'id' => $id,
            'amount' => $amountPaise,
            'currency' => $currency,
            'notes' => ['user_id' => $userId, 'plan_id' => 0, 'billing_cycle' => 'monthly'],
        ];
    }

    /**
     * Bind a partial mock of the payment service that stubs ONLY the two
     * provider-network methods; everything else (finalize, verifyAmount,
     * createSubscription, idempotency, anti-stack) runs for real.
     */
    private function bindFakeService(Plan $plan, object $order, string $cycle = 'monthly'): SubscriptionPaymentService
    {
        $svc = Mockery::mock(SubscriptionPaymentService::class)->makePartial();
        $svc->shouldReceive('fetchAndValidateOrder')
            ->andReturn(['order' => $order, 'plan' => $plan, 'billing_cycle' => $cycle]);
        $svc->shouldReceive('verifyPaymentCaptured')->andReturnNull();

        $this->app->instance(SubscriptionPaymentService::class, $svc);

        return $svc;
    }

    private function capturedPayload(string $paymentId, ?string $orderId): array
    {
        $entity = ['id' => $paymentId];
        if ($orderId !== null) {
            $entity['order_id'] = $orderId;
        }

        return ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => $entity]]];
    }

    // ── Lost callback / missed webhook ──────────────────────────────────────

    public function test_lost_browser_callback_then_valid_webhook_activates_once(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner, $shop] = $this->ownerWithShop();

        $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        app(SubscriptionWebhookService::class)
            ->handlePaymentCaptured($this->capturedPayload('pay_LOST', 'order_TEST'));

        $subs = ShopSubscription::where('razorpay_payment_id', 'pay_LOST')->get();
        $this->assertCount(1, $subs, 'exactly one subscription created from the webhook');
        $this->assertSame('active', $subs->first()->status);
        $this->assertSame($shop->id, $subs->first()->shop_id);
    }

    public function test_duplicate_webhook_activates_once(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        $this->bindFakeService($plan, $this->fakeOrder($owner->id));
        $webhook = app(SubscriptionWebhookService::class);

        $webhook->handlePaymentCaptured($this->capturedPayload('pay_DUP', 'order_TEST'));
        $webhook->handlePaymentCaptured($this->capturedPayload('pay_DUP', 'order_TEST'));

        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_DUP')->count());
    }

    public function test_webhook_callback_race_activates_once(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        // Two finalizations for the SAME payment (callback + webhook racing).
        $svc->reconcileCapturedPayment('order_TEST', 'pay_RACE');
        $svc->reconcileCapturedPayment('order_TEST', 'pay_RACE');

        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_RACE')->count());
    }

    // ── Rejections ──────────────────────────────────────────────────────────

    public function test_wrong_signature_is_rejected(): void
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);

        $response = $this->postJson('/subscription/payment/webhook',
            ['event' => 'payment.captured'],
            ['X-Razorpay-Signature' => 'obviously_invalid']
        );

        $response->assertStatus(400);
        $response->assertJson(['status' => 'rejected']);
    }

    public function test_wrong_amount_is_rejected_and_recorded_for_admin(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer'); // price 999 -> 99900 paise
        [$owner] = $this->ownerWithShop();

        // Order claims a cheaper amount than the plan price.
        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id, 50000));

        $result = $svc->reconcileCapturedPayment('order_TEST', 'pay_CHEAP');

        $this->assertNull($result);
        $this->assertSame(0, ShopSubscription::where('razorpay_payment_id', 'pay_CHEAP')->count());
        $this->assertDatabaseHas('subscription_events', ['event_type' => 'payment.unresolved']);
    }

    public function test_unresolvable_user_is_rejected_and_recorded(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');

        // notes.user_id points at a user that does not exist.
        $svc = $this->bindFakeService($plan, $this->fakeOrder(999999));

        $result = $svc->reconcileCapturedPayment('order_TEST', 'pay_NOUSER');

        $this->assertNull($result);
        $this->assertSame(0, ShopSubscription::where('razorpay_payment_id', 'pay_NOUSER')->count());
        $this->assertDatabaseHas('subscription_events', ['event_type' => 'payment.unresolved']);
    }

    // ── Failure remains safely reconcilable ────────────────────────────────

    public function test_transaction_failure_stays_reconcilable_and_activates_once_when_fixed(): void
    {
        // No platform super admin yet → createSubscription throws → recorded, not applied.
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        $first = $svc->reconcileCapturedPayment('order_TEST', 'pay_RETRY');
        $this->assertNull($first, 'no admin configured → payment not applied');
        $this->assertSame(0, ShopSubscription::where('razorpay_payment_id', 'pay_RETRY')->count());
        $this->assertDatabaseHas('subscription_events', ['event_type' => 'payment.unresolved']);

        // Fix the platform config, re-run reconcile → activates exactly once.
        $this->createPlatformAdmin();
        $second = $svc->reconcileCapturedPayment('order_TEST', 'pay_RETRY');

        $this->assertNotNull($second);
        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_RETRY')->count());
    }

    // ── Pending → captured via the command (manual mode) ───────────────────

    public function test_reconcile_command_manual_mode_activates_once(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        $this->artisan('subscription:reconcile-payments', ['--payment' => 'pay_PENDING', '--order' => 'order_TEST'])
            ->assertExitCode(0);
        $this->artisan('subscription:reconcile-payments', ['--payment' => 'pay_PENDING', '--order' => 'order_TEST'])
            ->assertExitCode(0);

        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_PENDING')->count());
    }

    // ── Admin suspension survives payment ──────────────────────────────────

    public function test_admin_suspended_shop_records_payment_but_stays_locked(): void
    {
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner, $shop] = $this->ownerWithShop();

        // A genuine administrative suspension (suspended_by set).
        $shop->forceFill([
            'access_mode' => 'suspended',
            'is_active' => false,
            'suspended_by' => $admin->id,
            'suspension_reason' => 'policy violation',
        ])->save();

        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));
        $svc->reconcileCapturedPayment('order_TEST', 'pay_ADMIN');

        // Money is RECORDED (subscription row exists) but the shop is NOT reactivated.
        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_ADMIN')->count());
        $shop->refresh();
        $this->assertFalse((bool) $shop->is_active, 'admin-suspended shop must stay inactive');
        $this->assertSame('suspended', $shop->access_mode);
    }

    // ── No duplication of shop / subscription / audit ──────────────────────

    public function test_no_duplicate_subscription_or_paid_audit_event(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        [$owner] = $this->ownerWithShop();

        $svc = $this->bindFakeService($plan, $this->fakeOrder($owner->id));

        $svc->reconcileCapturedPayment('order_TEST', 'pay_ONCE');
        $svc->reconcileCapturedPayment('order_TEST', 'pay_ONCE');

        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_ONCE')->count());
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'subscription.paid')->count());
    }
}
