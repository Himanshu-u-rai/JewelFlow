<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P0 OPUS-AUDIT CORRECTIONS — group 8: captured money during a NEWLY imposed hold.
 *
 * The race the audit asked about: an owner legitimately opens checkout while the
 * shop is merely lapsed, a JewelFlows administrator imposes a hold while the
 * customer is still on the Razorpay page, and THEN a genuine captured-payment
 * callback arrives. Two failure modes are both unacceptable:
 *
 *   • Discard the money — the customer paid and gets nothing recorded.
 *   • Honour the money as a reactivation — a purchase just bought its way out of
 *     an administrative hold, which is the exact thing this hotfix forbids.
 *
 * The correct behaviour is the third one: RECORD the term, LEAVE the hold.
 *
 * That contract is already expressed as an asymmetric predicate pair, and this
 * test exists to pin it:
 *
 *   ShopSubscription::blocksNewPaidTerm()      = administrative OR duplicate
 *       → gates INITIATION, where no money exists yet, so refusing is free.
 *   ShopSubscription::hasLivePaidTermToday()   = duplicate only
 *       → gates FINALIZATION (via paidTermStartsAt), where the money is already
 *         captured, so refusing would destroy it.
 *
 * SubscriptionPaymentService is deliberately NOT modified by this commit: the
 * guard at createSubscription() already reads suspensionIsAdministrative() under
 * a row lock before reactivating. These tests are the falsifiable proof of that,
 * which the audit correctly noted did not previously exist.
 *
 * Provider I/O: only the two network seams (fetchAndValidateOrder,
 * verifyPaymentCaptured) are faked, matching the harness already used by
 * SubscriptionPaymentReconciliationTest. Signature verification is left REAL —
 * Razorpay's utility->verifyPaymentSignature() is a pure local HMAC, so a
 * genuinely-signed, order-bound callback can be driven through the real HTTP
 * route with no network at all. That is what makes the forgery cases below
 * meaningful rather than mocked away.
 */
class CapturedPaymentAdministrativeHoldTest extends TestCase
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
            'platform.enforce_subscriptions' => true,
            'services.razorpay.key_id'       => 'rzp_test_key',
            'services.razorpay.key_secret'   => self::KEY_SECRET,
        ]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: Shop, 2: Plan, 3: PlatformAdmin} */
    private function lapsedOwnerReadyToRenew(): array
    {
        $admin = $this->createPlatformAdmin();
        $plan  = $this->createPlan('retailer');
        $shop  = $this->createShop('retailer');
        $owner = $this->createOwnerUser($shop, $this->createOwnerRole($shop->id));

        // A genuine lapse: the term ran out and grace is gone. No administrative
        // marker — this is the shop that is SUPPOSED to be able to renew.
        ShopSubscription::create([
            'shop_id'       => $shop->id,
            'user_id'       => $owner->id,
            'plan_id'       => $plan->id,
            'status'        => 'expired',
            'starts_at'     => now()->subMonths(2)->toDateString(),
            'ends_at'       => now()->subMonth()->toDateString(),
            'grace_ends_at' => now()->subDays(15)->toDateString(),
            'billing_cycle' => 'monthly',
            'price_paid'    => 999,
        ]);

        return [$owner, $shop->refresh(), $plan, $admin];
    }

    /** The administrator imposes the hold AFTER checkout has begun. */
    private function imposeHold(Shop $shop, PlatformAdmin $admin, string $mode): Shop
    {
        $shop->forceFill([
            'access_mode'       => $mode,
            'is_active'         => $mode !== 'suspended',
            'suspended_at'      => now(),
            'suspended_by'      => $admin->id,
            'suspension_reason' => 'Imposed mid-checkout by JewelFlows admin',
        ])->save();

        return $shop->refresh();
    }

    /** A fake Razorpay order — never touches the network. */
    private function fakeOrder(int $userId, int $planId, string $id, int $amountPaise): object
    {
        return (object) [
            'id'       => $id,
            'amount'   => $amountPaise,
            'currency' => 'INR',
            'notes'    => ['user_id' => $userId, 'plan_id' => $planId, 'billing_cycle' => 'monthly'],
        ];
    }

    /**
     * Partial-mock ONLY the two provider-network methods. verifyPaymentSignature,
     * verifyAmount, findExistingSubscription, paidTermStartsAt, createSubscription,
     * the row lock and the reactivation guard all run for real.
     */
    private function bindFakeGateway(Plan $plan, object $order): SubscriptionPaymentService
    {
        $svc = Mockery::mock(SubscriptionPaymentService::class)->makePartial();
        $svc->shouldReceive('fetchAndValidateOrder')
            ->andReturn(['order' => $order, 'plan' => $plan, 'billing_cycle' => 'monthly']);
        $svc->shouldReceive('verifyPaymentCaptured')->andReturnNull();

        $this->app->instance(SubscriptionPaymentService::class, $svc);

        return $svc;
    }

    /** A REAL Razorpay signature: HMAC-SHA256 over "order_id|payment_id". */
    private function sign(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId . '|' . $paymentId, self::KEY_SECRET);
    }

    private function postCallback(User $owner, string $orderId, string $paymentId, ?string $signature = null)
    {
        return $this->actingAs($owner)->post(route('subscription.payment.callback'), [
            'razorpay_order_id'   => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature'  => $signature ?? $this->sign($orderId, $paymentId),
        ]);
    }

    private function paidTermCount(string $paymentId): int
    {
        return ShopSubscription::where('razorpay_payment_id', $paymentId)->count();
    }

    // ── Step 1+2: eligible before the hold, refused at initiation after it ───

    public function test_owner_is_eligible_to_buy_until_the_administrator_imposes_the_hold(): void
    {
        [$owner, $shop, $plan, $admin] = $this->lapsedOwnerReadyToRenew();
        $current = ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();

        // Step 1 — the checkout the owner opens is legitimate.
        $this->assertFalse(
            ShopSubscription::blocksNewPaidTerm($current, $shop),
            'A lapsed shop with no administrative marker must be free to renew.'
        );

        // Step 2 — the administrator imposes a hold mid-checkout.
        $shop = $this->imposeHold($shop, $admin, 'suspended');

        $this->assertTrue(
            ShopSubscription::blocksNewPaidTerm($current, $shop->refresh()),
            'Once held, no NEW purchase may be initiated.'
        );

        // And the money-taking endpoint refuses BEFORE any Razorpay order exists,
        // so this hold costs the customer nothing.
        $this->actingAs($owner)
            ->postJson(route('subscription.payment.initiate'))
            ->assertStatus(403)
            ->assertJsonFragment(['redirect' => route('subscription.status')]);

        $this->assertSame(0, ShopSubscription::whereNotNull('razorpay_payment_id')->count(),
            'A refused initiation must never mint a paid term.');
    }

    // ── Steps 3-7: the captured callback lands under a suspension ───────────

    public function test_captured_callback_under_a_new_suspension_records_the_term_without_reactivating(): void
    {
        [$owner, $shop, $plan, $admin] = $this->lapsedOwnerReadyToRenew();
        $shop = $this->imposeHold($shop, $admin, 'suspended');

        $this->bindFakeGateway(
            $plan,
            $this->fakeOrder($owner->id, $plan->id, 'order_HOLD', (int) round($plan->price_monthly * 100))
        );

        // Step 3 — a genuinely signed, order-bound callback over the real route.
        $this->postCallback($owner, 'order_HOLD', 'pay_HOLD');

        // Step 4 — the money IS recorded, exactly once.
        $this->assertSame(1, $this->paidTermCount('pay_HOLD'),
            'Captured money must never be silently discarded.');
        $paid = ShopSubscription::where('razorpay_payment_id', 'pay_HOLD')->first();
        $this->assertSame('active', $paid->status);
        $this->assertSame($shop->id, $paid->shop_id);
        $this->assertSame('order_HOLD', $paid->razorpay_order_id);

        // Steps 5+6 — the shop is NOT reactivated and the marker survives intact.
        $shop->refresh();
        $this->assertSame('suspended', $shop->access_mode, 'Payment must not lift an administrative hold.');
        $this->assertFalse((bool) $shop->is_active);
        $this->assertSame($admin->id, $shop->suspended_by, 'The administrative marker must survive the payment.');
        $this->assertNotNull($shop->suspended_at);
        $this->assertNotNull($shop->suspension_reason);
        $this->assertTrue($shop->suspensionIsAdministrative());

        // The paid term is real, so the shop is no longer subscription-lapsed —
        // it is held for exactly one reason, and that reason is the administrator.
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'subscription.paid')
            ->where('shop_id', $shop->id)->count());
    }

    // ── Step 7: edition behaviour is explicit, not incidental ───────────────

    public function test_captured_callback_under_administrative_read_only_grants_the_edition_but_keeps_the_lock(): void
    {
        [$owner, $shop, $plan, $admin] = $this->lapsedOwnerReadyToRenew();
        $shop = $this->imposeHold($shop, $admin, 'read_only');

        $this->bindFakeGateway(
            $plan,
            $this->fakeOrder($owner->id, $plan->id, 'order_RO', (int) round($plan->price_monthly * 100))
        );

        $this->postCallback($owner, 'order_RO', 'pay_RO');

        $paid = ShopSubscription::where('razorpay_payment_id', 'pay_RO')->first();
        $this->assertNotNull($paid, 'The term must be recorded under an administrative read-only hold too.');

        // Edition IS granted, and deliberately so: the customer paid for the
        // product, and under administrative read-only the edition is precisely
        // what keeps read-only BROWSING working. Granting it does not grant
        // write access — access_mode does that, and it is untouched below.
        $assignment = ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('product_subscription_id', $paid->id)
            ->whereNull('deactivated_at')
            ->first();
        $this->assertNotNull($assignment, 'A paid term must grant its edition even under a hold.');
        $this->assertSame($plan->grantsEdition(), $assignment->edition);

        // The lock itself is untouched.
        $shop->refresh();
        $this->assertSame('read_only', $shop->access_mode);
        $this->assertSame($admin->id, $shop->suspended_by);
        $this->assertTrue($shop->suspensionIsAdministrative());
    }

    // ── Step 8: replay stays idempotent ─────────────────────────────────────

    public function test_replayed_callback_under_a_hold_creates_exactly_one_term(): void
    {
        [$owner, $shop, $plan, $admin] = $this->lapsedOwnerReadyToRenew();
        $shop = $this->imposeHold($shop, $admin, 'suspended');

        $this->bindFakeGateway(
            $plan,
            $this->fakeOrder($owner->id, $plan->id, 'order_REPLAY', (int) round($plan->price_monthly * 100))
        );

        $this->postCallback($owner, 'order_REPLAY', 'pay_REPLAY');
        $this->postCallback($owner, 'order_REPLAY', 'pay_REPLAY');
        $this->postCallback($owner, 'order_REPLAY', 'pay_REPLAY');

        $this->assertSame(1, $this->paidTermCount('pay_REPLAY'),
            'A redelivered callback must collapse to a single paid term.');
        $this->assertSame(1, SubscriptionEvent::where('event_type', 'subscription.paid')
            ->where('shop_id', $shop->id)->count(),
            'A redelivered callback must not duplicate the audit trail either.');

        // Replay must not become a second chance at reactivation.
        $shop->refresh();
        $this->assertSame('suspended', $shop->access_mode);
        $this->assertSame($admin->id, $shop->suspended_by);
    }

    // ── Step 9: forged and mismatched callbacks cannot mint a term ──────────

    public function test_a_fabricated_signature_cannot_create_a_paid_term(): void
    {
        [$owner, $shop, $plan, $admin] = $this->lapsedOwnerReadyToRenew();
        $shop = $this->imposeHold($shop, $admin, 'suspended');

        $this->bindFakeGateway(
            $plan,
            $this->fakeOrder($owner->id, $plan->id, 'order_FORGED', (int) round($plan->price_monthly * 100))
        );

        // Real HMAC verification, real rejection — no network involved.
        $this->postCallback($owner, 'order_FORGED', 'pay_FORGED', 'deadbeef_not_a_signature');

        $this->assertSame(0, $this->paidTermCount('pay_FORGED'),
            'An unsigned/forged callback must never mint a paid term.');

        $shop->refresh();
        $this->assertSame('suspended', $shop->access_mode);
        $this->assertSame($admin->id, $shop->suspended_by);
    }

    public function test_a_signature_bound_to_a_different_order_cannot_create_a_paid_term(): void
    {
        [$owner, $shop, $plan, $admin] = $this->lapsedOwnerReadyToRenew();
        $shop = $this->imposeHold($shop, $admin, 'suspended');

        $this->bindFakeGateway(
            $plan,
            $this->fakeOrder($owner->id, $plan->id, 'order_REAL', (int) round($plan->price_monthly * 100))
        );

        // A signature that is genuine — but for a DIFFERENT order. Order binding
        // is the property under test, not signature validity in the abstract.
        $this->postCallback(
            $owner,
            'order_REAL',
            'pay_MISMATCH',
            $this->sign('order_SOMEONE_ELSE', 'pay_MISMATCH')
        );

        $this->assertSame(0, $this->paidTermCount('pay_MISMATCH'),
            'A signature bound to another order must not finalize this one.');

        $shop->refresh();
        $this->assertSame($admin->id, $shop->suspended_by);
    }

    public function test_a_callback_with_no_signature_at_all_cannot_create_a_paid_term(): void
    {
        [$owner, $shop, $plan, $admin] = $this->lapsedOwnerReadyToRenew();
        $shop = $this->imposeHold($shop, $admin, 'suspended');

        $this->bindFakeGateway(
            $plan,
            $this->fakeOrder($owner->id, $plan->id, 'order_NOSIG', (int) round($plan->price_monthly * 100))
        );

        $this->actingAs($owner)->post(route('subscription.payment.callback'), [
            'razorpay_order_id'   => 'order_NOSIG',
            'razorpay_payment_id' => 'pay_NOSIG',
        ]);

        $this->assertSame(0, $this->paidTermCount('pay_NOSIG'));
        $this->assertSame($admin->id, $shop->refresh()->suspended_by);
    }

    // ── The control: without a hold the SAME callback DOES reactivate ────────

    public function test_the_same_callback_without_a_hold_does_reactivate_the_shop(): void
    {
        [$owner, $shop, $plan] = $this->lapsedOwnerReadyToRenew();

        // A subscription-managed lapse — no administrative marker anywhere.
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspended_by'      => null,
            'suspension_reason' => 'Subscription expired',
        ])->save();

        $this->bindFakeGateway(
            $plan,
            $this->fakeOrder($owner->id, $plan->id, 'order_CLEAN', (int) round($plan->price_monthly * 100))
        );

        $this->postCallback($owner, 'order_CLEAN', 'pay_CLEAN');

        $this->assertSame(1, $this->paidTermCount('pay_CLEAN'));

        // This is the half that proves the guard discriminates rather than just
        // refusing everything: renewal still works for the ordinary lapsed shop.
        $shop->refresh();
        $this->assertSame('active', $shop->access_mode, 'A normal renewal must still reactivate.');
        $this->assertTrue((bool) $shop->is_active);
        $this->assertNull($shop->suspended_at);
        $this->assertNull($shop->suspension_reason);
    }
}
