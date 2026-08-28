<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\ShopEditionAssignment;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * F-1 — the authenticated payment callback must be bound to the user the
 * server issued the order to.
 *
 * paymentCallback() verifies the gateway signature, the order, the amount, the
 * capture and idempotency. Every one of those proves the PAYMENT is genuine.
 * None of them proves the PAYER is the person now holding the session.
 *
 *   SubscriptionPaymentService::createRazorpayOrder()  stamps
 *       notes => ['plan_id', 'plan_name', 'billing_cycle', 'user_id' => $user->id]
 *   SubscriptionPaymentService::createSubscription()   binds
 *       $actor = $actor ?? Auth::user()
 *
 * Those two identities were never compared, so a valid, unprocessed callback
 * triple belonging to user B could be replayed by authenticated user A and the
 * paid term would be minted for A. B then hits idempotency and can never claim
 * the purchase they paid for.
 *
 * The project already fixed exactly this on the sibling leg —
 * ShopServicesController::addCallback() "L1" at lines 199-211 — and the machine
 * leg (SubscriptionPaymentService::finalizeCapturedPayment) has always resolved
 * its actor FROM notes.user_id rather than from a session. This suite holds the
 * subscription browser leg to the same standard.
 *
 * Harness: only the two provider-network seams (fetchAndValidateOrder,
 * verifyPaymentCaptured) are faked, matching CapturedPaymentAdministrativeHold-
 * Test and SubscriptionPaymentReconciliationTest. Signature verification is left
 * REAL — Razorpay's verifyPaymentSignature() is a pure local HMAC — so the
 * forged-signature and wrong-order cases below are genuinely proven rather than
 * mocked away, and every rejection here is reached with a signature the gateway
 * itself would have accepted.
 */
class SubscriptionCallbackOwnershipTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const KEY_SECRET = 'test_secret_for_hmac';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        config([
            'services.razorpay.key_id'     => 'rzp_test_key',
            'services.razorpay.key_secret' => self::KEY_SECRET,
        ]);
        // createSubscription() stamps its audit event with the platform super
        // admin and throws 'Platform configuration incomplete.' without one.
        $this->createPlatformAdmin();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** A brand-new signup: no shop, no role. Checkout precedes shop creation. */
    private function shopLessUser(): User
    {
        return User::factory()->create([
            'shop_id'              => null,
            'role_id'              => null,
            'is_active'            => true,
            'onboarding_shop_type' => 'manufacturer',
        ]);
    }

    /**
     * A fake server-issued Razorpay order — never touches the network. The notes
     * shape mirrors createRazorpayOrder() exactly. Pass $userId = null to model a
     * LEGACY order minted before the note existed.
     */
    private function fakeOrder(?int $userId, Plan $plan, string $id): object
    {
        $notes = ['plan_id' => $plan->id, 'plan_name' => $plan->name, 'billing_cycle' => 'monthly'];

        if ($userId !== null) {
            $notes['user_id'] = $userId;
        }

        return (object) [
            'id'       => $id,
            'amount'   => (int) round((float) $plan->price_monthly * 100),
            'currency' => 'INR',
            'notes'    => $notes,
        ];
    }

    /** Stub ONLY the two provider-network methods; everything else runs for real. */
    private function bindFakeGateway(Plan $plan, object $order): void
    {
        $svc = Mockery::mock(SubscriptionPaymentService::class)->makePartial();
        $svc->shouldReceive('fetchAndValidateOrder')
            ->andReturn(['order' => $order, 'plan' => $plan, 'billing_cycle' => 'monthly']);
        $svc->shouldReceive('verifyPaymentCaptured')->andReturnNull();

        $this->app->instance(SubscriptionPaymentService::class, $svc);
    }

    /** A REAL Razorpay signature: HMAC-SHA256 over "order_id|payment_id". */
    private function sign(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId . '|' . $paymentId, self::KEY_SECRET);
    }

    private function postCallback(User $as, string $orderId, string $paymentId, ?string $signature = null)
    {
        return $this->actingAs($as)->post(route('subscription.payment.callback'), [
            'razorpay_order_id'   => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature'  => $signature ?? $this->sign($orderId, $paymentId),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // 1+2 — B's order is issued under B's authenticated session
    // ════════════════════════════════════════════════════════════════════════

    /**
     * initiatePayment() reads the plan from the SESSION only ("never accept from
     * request body") and hands createRazorpayOrder() nothing but the plan and the
     * cycle — the user on the order comes from Auth alone. This pins that: the
     * order is minted for whoever is authenticated, and for nobody a request body
     * could nominate.
     *
     * The literal `notes.user_id` stamp is the one line that cannot be driven
     * without a network call (razorpay() is private and Api::order->create() is
     * an HTTP hop). It is not unproven, though: finalizeCapturedPayment() THROWS
     * when notes.user_id is absent, and the webhook/reconcile suites exercising
     * production orders through it are green — the note demonstrably exists.
     */
    public function test_shop_less_initiation_issues_the_order_under_the_authenticated_user(): void
    {
        $plan = $this->createPlan('manufacturer');
        $b    = $this->shopLessUser();

        $initiator = null;
        $svc = Mockery::mock(SubscriptionPaymentService::class)->makePartial();
        $svc->shouldReceive('createRazorpayOrder')->andReturnUsing(
            function () use (&$initiator, $plan) {
                // Whatever identity the SERVER is running under at order-mint time.
                $initiator = \Illuminate\Support\Facades\Auth::id();

                return $this->fakeOrder($initiator, $plan, 'order_B');
            }
        );
        $this->app->instance(SubscriptionPaymentService::class, $svc);

        $this->actingAs($b)
            ->post(route('subscription.choose'), ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertRedirect(route('subscription.payment'));

        // A hostile body naming another user must not change who the order is for.
        $this->actingAs($b)
            ->postJson(route('subscription.payment.initiate'), ['user_id' => $b->id + 4242])
            ->assertOk()
            ->assertJsonFragment(['order_id' => 'order_B']);

        $this->assertSame($b->id, $initiator, 'The server-issued order must be minted for the authenticated user.');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 3-7 — A replays B's otherwise-valid triple
    // ════════════════════════════════════════════════════════════════════════

    /**
     * THE BLOCKER. Everything about this callback is genuine except the person
     * presenting it: real HMAC over the real order id, real captured payment,
     * real unused payment id. Only the identity is wrong.
     */
    public function test_cross_user_callback_is_rejected_and_grants_nothing(): void
    {
        $plan = $this->createPlan('manufacturer');
        $b    = $this->shopLessUser();   // paid
        $a    = $this->shopLessUser();   // replays

        $this->bindFakeGateway($plan, $this->fakeOrder($b->id, $plan, 'order_B'));

        $response = $this->postCallback($a, 'order_B', 'pay_B');

        // The theft, stated literally. Before the guard this is 1: createSubscription()
        // binds $actor = Auth::user(), so B's captured payment mints A's paid term.
        $this->assertSame(0, ShopSubscription::where('user_id', $a->id)->count(),
            'User A must never receive a term paid for by user B.');

        // 4 — refused.
        $response->assertRedirect();
        $response->assertSessionHas('error');

        // 5 — no paid term exists for ANYONE. Not for A (theft), not for B
        //     (which would have burned B's payment id on a session B never had).
        $this->assertSame(0, ShopSubscription::count(), 'A refused callback must mint no term at all.');
        $this->assertSame(0, ShopSubscription::where('razorpay_payment_id', 'pay_B')->count());

        // 6 — nothing granted or attached.
        $this->assertSame(0, ShopEditionAssignment::count(), 'No edition may be granted by a refused callback.');
        $this->assertNull($a->fresh()->shop_id, 'A must not acquire a shop from B\'s payment.');
        $this->assertNull($b->fresh()->shop_id);
        $this->assertNull(session('pending_subscription_id'));
        $this->assertNull(session('subscription_completed'));

        // 7 — actionable but not sensitive: A gets a support reference and no
        //     hint of who B is or what the payment contained.
        $error = session('error');
        $this->assertIsString($error);
        $this->assertStringContainsString('pay_B', $error, 'The message must be actionable — support needs the ref.');
        $this->assertStringNotContainsString((string) $b->id, $error);
        $this->assertStringNotContainsString((string) $b->mobile_number, $error);
        $this->assertStringNotContainsString($this->sign('order_B', 'pay_B'), $error);
        $this->assertStringNotContainsString(self::KEY_SECRET, $error);
    }

    // ════════════════════════════════════════════════════════════════════════
    // 8 — the rejection log leaks nothing
    // ════════════════════════════════════════════════════════════════════════

    public function test_rejection_logs_only_safe_investigation_identifiers(): void
    {
        $plan = $this->createPlan('manufacturer');
        $b    = $this->shopLessUser();
        $a    = $this->shopLessUser();

        $this->bindFakeGateway($plan, $this->fakeOrder($b->id, $plan, 'order_B'));

        $captured = [];
        Log::listen(function ($event) use (&$captured) {
            $captured[] = ['message' => $event->message, 'context' => $event->context];
        });

        $this->postCallback($a, 'order_B', 'pay_B');

        $mismatch = collect($captured)->first(
            fn ($l) => str_contains(strtolower($l['message']), 'mismatch')
        );
        $this->assertNotNull($mismatch, 'A refused cross-user callback must leave an investigable trace.');

        // Only identifiers an operator needs, and nothing that identifies a person.
        $this->assertEqualsCanonicalizing(
            ['payment_id', 'order_user_id', 'auth_user_id'],
            array_keys($mismatch['context']),
            'The mismatch log must carry exactly the three safe identifiers.'
        );

        $blob = json_encode($captured);
        $this->assertStringNotContainsString(self::KEY_SECRET, $blob, 'No secret may ever be logged.');
        $this->assertStringNotContainsString($this->sign('order_B', 'pay_B'), $blob, 'No signature may ever be logged.');
        $this->assertStringNotContainsString((string) $b->mobile_number, $blob, 'No personal data may ever be logged.');
        $this->assertStringNotContainsString((string) $a->mobile_number, $blob);
    }

    // ════════════════════════════════════════════════════════════════════════
    // 9+10 — B's own callback still works, and replays stay idempotent
    // ════════════════════════════════════════════════════════════════════════

    public function test_the_same_callback_succeeds_for_the_user_who_paid(): void
    {
        $plan = $this->createPlan('manufacturer');
        $b    = $this->shopLessUser();

        $this->bindFakeGateway($plan, $this->fakeOrder($b->id, $plan, 'order_B'));

        $this->postCallback($b, 'order_B', 'pay_B')->assertRedirect();

        $sub = ShopSubscription::where('razorpay_payment_id', 'pay_B')->first();
        $this->assertNotNull($sub, 'The guard must not block the person the order belongs to.');
        $this->assertSame($b->id, $sub->user_id);
        $this->assertSame('active', $sub->status);
        $this->assertNull($sub->shop_id, 'Pay-before-shop: the term attaches when the shop is created.');
    }

    public function test_replay_by_the_paying_user_remains_idempotent(): void
    {
        $plan = $this->createPlan('manufacturer');
        $b    = $this->shopLessUser();

        $this->bindFakeGateway($plan, $this->fakeOrder($b->id, $plan, 'order_B'));

        $this->postCallback($b, 'order_B', 'pay_B')->assertRedirect();
        $this->postCallback($b, 'order_B', 'pay_B')->assertRedirect();

        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_B')->count(),
            'A duplicate callback must return the existing term, never mint a second.');
    }

    /**
     * And the guard runs BEFORE idempotency: A replaying a triple B has already
     * redeemed must not be handed B's existing subscription id in session.
     */
    public function test_cross_user_replay_of_an_already_redeemed_payment_is_still_refused(): void
    {
        $plan = $this->createPlan('manufacturer');
        $b    = $this->shopLessUser();
        $a    = $this->shopLessUser();

        $this->bindFakeGateway($plan, $this->fakeOrder($b->id, $plan, 'order_B'));

        $this->postCallback($b, 'order_B', 'pay_B');
        $sub = ShopSubscription::where('razorpay_payment_id', 'pay_B')->firstOrFail();

        // A is a different browser: give them their own empty session, or B's
        // leftover keys would make this assertion meaningless.
        $this->flushSession();

        $response = $this->postCallback($a, 'order_B', 'pay_B');
        $response->assertSessionHas('error');
        $response->assertRedirect(route('subscription.payment'));

        $this->assertNull(session('pending_subscription_id'),
            'A must never be handed B\'s subscription by the idempotency branch.');
        $this->assertNull(session('subscription_completed'));
        $this->assertSame($b->id, $sub->fresh()->user_id, 'B\'s term must stay B\'s.');
        $this->assertSame(1, ShopSubscription::count());
    }

    // ════════════════════════════════════════════════════════════════════════
    // 11 — the payment-integrity gates are untouched
    // ════════════════════════════════════════════════════════════════════════

    public function test_forged_signature_is_still_rejected(): void
    {
        $plan = $this->createPlan('manufacturer');
        $b    = $this->shopLessUser();

        $this->bindFakeGateway($plan, $this->fakeOrder($b->id, $plan, 'order_B'));

        $this->postCallback($b, 'order_B', 'pay_B', 'deadbeef')->assertSessionHas('error');

        $this->assertSame(0, ShopSubscription::count());
    }

    public function test_signature_bound_to_a_different_order_is_still_rejected(): void
    {
        $plan = $this->createPlan('manufacturer');
        $b    = $this->shopLessUser();

        $this->bindFakeGateway($plan, $this->fakeOrder($b->id, $plan, 'order_B'));

        // Genuine HMAC, wrong order — the signature does not bind this pair.
        $this->postCallback($b, 'order_B', 'pay_B', $this->sign('order_OTHER', 'pay_B'))
            ->assertSessionHas('error');

        $this->assertSame(0, ShopSubscription::count());
    }

    // ════════════════════════════════════════════════════════════════════════
    // 12 — the shop-attached owner leg is unchanged
    // ════════════════════════════════════════════════════════════════════════

    public function test_shop_attached_owner_callback_remains_green(): void
    {
        $plan  = $this->createPlan('retailer');
        $shop  = $this->createShop('retailer');
        $owner = $this->createOwnerUser($shop, $this->createOwnerRole($shop->id));

        $this->bindFakeGateway($plan, $this->fakeOrder($owner->id, $plan, 'order_OWN'));

        $this->postCallback($owner, 'order_OWN', 'pay_OWN')->assertRedirect();

        $sub = ShopSubscription::where('razorpay_payment_id', 'pay_OWN')->first();
        $this->assertNotNull($sub);
        $this->assertSame($shop->id, $sub->shop_id);
        $this->assertSame($owner->id, $sub->user_id);
    }

    /** A staff member of the same shop still cannot redeem the owner's order. */
    public function test_staff_of_the_same_shop_cannot_redeem_the_owner_order(): void
    {
        $plan  = $this->createPlan('retailer');
        $shop  = $this->createShop('retailer');
        $owner = $this->createOwnerUser($shop, $this->createOwnerRole($shop->id));

        $staffRole = new \App\Models\Role();
        $staffRole->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $shop->id])->save();

        $staff = User::factory()->create([
            'shop_id'   => $shop->id,
            'role_id'   => $staffRole->id,
            'is_active' => true,
        ]);

        $this->bindFakeGateway($plan, $this->fakeOrder($owner->id, $plan, 'order_OWN'));

        $this->postCallback($staff, 'order_OWN', 'pay_OWN');

        $this->assertSame(0, ShopSubscription::where('razorpay_payment_id', 'pay_OWN')->count(),
            'A non-owner must never redeem the owner\'s captured payment.');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Legacy residual — an order minted before the note existed
    // ════════════════════════════════════════════════════════════════════════

    /**
     * DOCUMENTED RESIDUAL, deliberately preserved. The sibling guard states the
     * policy: "Enforced only when the note is present, so legacy/onboarding
     * orders are unaffected." An order with no notes.user_id cannot be attributed
     * to anyone, so refusing it would destroy captured money belonging to a real
     * customer. Every order this codebase mints carries the note
     * (createRazorpayOrder), and the machine leg already REFUSES a noteless order
     * outright (finalizeCapturedPayment throws), so the exposure is bounded to
     * in-flight orders older than the stamp — a window that has long closed.
     */
    public function test_legacy_order_without_a_user_note_is_still_honoured(): void
    {
        $plan = $this->createPlan('manufacturer');
        $b    = $this->shopLessUser();

        $this->bindFakeGateway($plan, $this->fakeOrder(null, $plan, 'order_LEGACY'));

        $this->postCallback($b, 'order_LEGACY', 'pay_LEGACY')->assertRedirect();

        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_LEGACY')->count(),
            'A noteless legacy order must not have its captured money discarded.');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 14 — the machine leg is untouched
    // ════════════════════════════════════════════════════════════════════════

    public function test_the_signed_webhook_is_unaffected(): void
    {
        $response = $this->postJson(route('subscription.payment.webhook'), ['event' => 'payment.captured']);

        $this->assertNotSame(403, $response->getStatusCode(), 'the browser-leg guard must never reach the webhook');
        $this->assertContains($response->getStatusCode(), [400, 500],
            'the webhook must remain unauthenticated and terminate on SIGNATURE verification');
    }
}
