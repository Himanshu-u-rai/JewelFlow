<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P0 OPUS-AUDIT CORRECTION F2 — subscription commerce must PROVE ownership, and
 * must never INFER it from a missing role.
 *
 * SubscriptionController::abortUnlessOwner() denied only a PROVEN non-owner:
 *
 *     if ($user->role_id === null) { return; }      // ← allowed through
 *     if (! $user->isShopOwner()) { abort(403); }
 *
 * The safety argument for that early return was "a role-less user is never
 * staff, because StaffController requires role_id". That premise is false. The
 * RBAC migration (2026_02_04_100000_create_rbac_tables) added users.role_id as
 * NULLABLE and dropped the old users.role string WITHOUT backfilling, so every
 * user that predates it — owners and cashiers alike — carries role_id = NULL.
 * Any one of those legacy cashiers could pick a plan, open checkout, create a
 * Razorpay order, start a trial and read the shop's PlatformInvoice history.
 *
 * The correction FAILS CLOSED: ownership must be proven by the role, or it is
 * not ownership.
 *
 * OWNERSHIP IS NOT INFERRED FROM users.mobile_number. It was considered (the
 * column is globally unique, and shops.owner_mobile is required) and REJECTED:
 * the two values are independently writable and legitimately diverge.
 *   • MobileChangeController::confirm() and Admin\UserMobileController::update()
 *     both force-fill users.mobile_number and never touch shops.owner_mobile.
 *   • SettingsController lets a shop rewrite owner_mobile and syncs only the
 *     owner's NAME back to the user row, with the in-repo comment "the two are
 *     independent (login identity vs registered shop owner)".
 * So equality proves nothing (a legitimate owner who changed their login mobile
 * would be denied) and inequality proves nothing either. A stale or transferred
 * owner_mobile that happens to match a cashier's login would hand that cashier
 * the shop's billing. Mobile equality is therefore NOT used anywhere below, and
 * the two tests that construct exact equality assert DENIAL.
 *
 * Legacy role-less OWNERS are repaired by assigning them the owner role, which
 * is a separate follow-up — not a hole left open in the payment boundary.
 */
class OwnerIdentityFailClosedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** @return array{0: User, 1: Shop, 2: Plan, 3: PlatformAdmin} */
    private function tenant(): array
    {
        $admin = $this->createPlatformAdmin();
        $plan  = $this->createPlan('manufacturer');
        $shop  = $this->createShop('manufacturer');
        $role  = $this->createOwnerRole($shop->id);
        $user  = $this->createOwnerUser($shop, $role);
        $this->createBillingSettings($shop->id);
        $this->markShopOpeningSetupComplete($shop->id);

        return [$user, $shop, $plan, $admin];
    }

    private function subscription(Shop $shop, Plan $plan, array $attrs = []): ShopSubscription
    {
        return ShopSubscription::create(array_merge([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'expired',
            'starts_at' => now()->subMonths(2)->toDateString(),
            'ends_at'   => now()->subDays(40)->toDateString(),
        ], $attrs));
    }

    /** A user attached to the shop with NO role at all — the legacy population. */
    private function roleLessUser(Shop $shop, array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'shop_id'   => $shop->id,
            'role_id'   => null,
            'is_active' => true,
        ], $attrs));
    }

    private function stafferWithRole(Shop $shop): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'cashier', 'display_name' => 'Cashier', 'shop_id' => $shop->id]);
        $role->save();

        return User::factory()->create([
            'shop_id'   => $shop->id,
            'role_id'   => $role->id,
            'is_active' => true,
        ]);
    }

    /**
     * Every authenticated subscription-management entry point, with the verb the
     * real route uses. The signature-verified webhook is deliberately absent —
     * it is the machine leg and must stay unauthenticated.
     */
    public static function ownerRoutes(): array
    {
        return [
            'plans'    => ['get',  'subscription.plans'],
            'choose'   => ['post', 'subscription.choose'],
            'payment'  => ['get',  'subscription.payment'],
            'initiate' => ['post', 'subscription.payment.initiate'],
            'callback' => ['post', 'subscription.payment.callback'],
            'status'   => ['get',  'subscription.status'],
            'trial'    => ['post', 'subscription.trial.start'],
        ];
    }

    private function hit(User $as, string $verb, string $routeName, array $payload = [])
    {
        return $this->actingAs($as)->{$verb}(route($routeName), $payload);
    }

    // ════════════════════════════════════════════════════════════════════════
    // 1 — a PROVEN owner keeps everything
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider ownerRoutes */
    public function test_a_proven_owner_reaches_every_subscription_route(string $verb, string $routeName): void
    {
        [$owner, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan);

        $status = $this->hit($owner, $verb, $routeName, [
            'plan_id'       => $plan->id,
            'billing_cycle' => 'monthly',
        ])->getStatusCode();

        $this->assertNotSame(
            403,
            $status,
            "A proven owner must never be denied {$routeName}; the guard proves ownership, it does not withhold it."
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // 2 — a PROVEN non-owner keeps nothing
    // ════════════════════════════════════════════════════════════════════════

    /** @dataProvider ownerRoutes */
    public function test_proven_staff_are_denied_every_subscription_route(string $verb, string $routeName): void
    {
        [, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan);
        $staff = $this->stafferWithRole($shop);

        $this->hit($staff, $verb, $routeName, [
            'plan_id'       => $plan->id,
            'billing_cycle' => 'monthly',
        ])->assertForbidden();
    }

    // ════════════════════════════════════════════════════════════════════════
    // 3, 5, 7 — the ROLE-LESS legacy population fails closed
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The blocker itself. A legacy cashier carries role_id = NULL through no
     * fault of their own, and their mobile is nothing like the shop's registered
     * owner_mobile. Under the old guard every one of these returned 200/302.
     *
     * @dataProvider ownerRoutes
     */
    public function test_a_role_less_user_is_denied_every_subscription_route(string $verb, string $routeName): void
    {
        [, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan);

        $legacy = $this->roleLessUser($shop, ['mobile_number' => '9111000111']);
        $this->assertNotSame(
            $shop->owner_mobile,
            $legacy->mobile_number,
            'fixture: this user carries no ownership evidence of any kind'
        );

        $this->hit($legacy, $verb, $routeName, [
            'plan_id'       => $plan->id,
            'billing_cycle' => 'monthly',
        ])->assertForbidden();
    }

    /** Billing history is tenant data and must not leak to an unproven identity. */
    public function test_a_role_less_user_cannot_read_platform_invoice_history(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->subscription($shop, $plan);
        $legacy = $this->roleLessUser($shop, ['mobile_number' => '9111000222']);

        $invoice = PlatformInvoice::create([
            'shop_id'              => $shop->id,
            'shop_subscription_id' => $sub->id,
            'plan_id'              => $plan->id,
            'invoice_number'       => 'PLT-ROLELESS-LEAK-0001',
            'invoice_sequence'     => 1,
            'billing_cycle'        => 'monthly',
            'billing_period_start' => now()->subMonth()->toDateString(),
            'billing_period_end'   => now()->toDateString(),
            'amount_before_tax'    => 999,
            'gst_rate'             => 18,
            'gst_amount'           => 179.82,
            'total_amount'         => 1178.82,
            'status'               => 'paid',
            'issued_at'            => now(),
            'created_by_admin_id'  => $admin->id,
        ]);

        $response = $this->actingAs($legacy)->get(route('subscription.status'));

        $response->assertForbidden();
        $response->assertDontSee($invoice->invoice_number);
    }

    /** No commerce SIDE EFFECT may survive the denial, on any entry point. */
    public function test_a_role_less_user_cannot_create_a_subscription_or_a_session_plan(): void
    {
        [, $shop, $plan] = $this->tenant();
        $legacy = $this->roleLessUser($shop, ['mobile_number' => '9111000333']);

        $this->actingAs($legacy)
            ->post(route('subscription.choose'), ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertForbidden();
        $this->assertNull(session('pending_plan_id'), 'a denied user must not seed the checkout session');

        $this->actingAs($legacy)
            ->post(route('subscription.trial.start'), ['plan_id' => $plan->id])
            ->assertForbidden();

        $this->actingAs($legacy)
            ->postJson(route('subscription.payment.initiate'), ['plan_id' => $plan->id])
            ->assertForbidden();

        $this->actingAs($legacy)
            ->post(route('subscription.payment.callback'), [
                'razorpay_payment_id' => 'pay_roleless',
                'razorpay_order_id'   => 'order_roleless',
                'razorpay_signature'  => 'sig_roleless',
            ])
            ->assertForbidden();

        $this->assertSame(
            0,
            ShopSubscription::where('shop_id', $shop->id)->count(),
            'no denied entry point may leave a subscription behind'
        );
    }

    /**
     * BLANK / NULL ownership evidence must not read as a match. A shop whose
     * owner_mobile is empty and a user whose mobile_number is empty are not
     * "equal owners" — under a naive equality check they would be, which is one
     * more reason equality is not the signal.
     */
    public function test_a_role_less_user_with_blank_ownership_evidence_is_denied(): void
    {
        [, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan);
        $shop->forceFill(['owner_mobile' => ''])->save();

        $legacy = $this->roleLessUser($shop, ['mobile_number' => '']);

        $this->actingAs($legacy)->get(route('subscription.plans'))->assertForbidden();
        $this->actingAs($legacy)->get(route('subscription.status'))->assertForbidden();
    }

    // ════════════════════════════════════════════════════════════════════════
    // 6 — EXACT mobile equality is still DENIED (Option A: no inferred signals)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The decisive test. This user's canonical users.mobile_number is byte-equal
     * to the shop's canonical shops.owner_mobile — the strongest compatibility
     * signal available without a migration — and they are STILL denied, because
     * the two columns are independently writable and equality is therefore not
     * proof of ownership. If this test ever goes green, ownership is being
     * inferred again.
     *
     * @dataProvider ownerRoutes
     */
    public function test_exact_owner_mobile_equality_is_not_accepted_as_proof_of_ownership(string $verb, string $routeName): void
    {
        [, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan);

        $impostor = $this->roleLessUser($shop, ['mobile_number' => $shop->owner_mobile]);

        $this->assertSame(
            $shop->fresh()->owner_mobile,
            $impostor->fresh()->mobile_number,
            'fixture: the equality signal is genuinely present'
        );

        $this->hit($impostor, $verb, $routeName, [
            'plan_id'       => $plan->id,
            'billing_cycle' => 'monthly',
        ])->assertForbidden();
    }

    // ════════════════════════════════════════════════════════════════════════
    // 8 — the no-shop onboarding path, and its boundaries
    // ════════════════════════════════════════════════════════════════════════

    /**
     * A brand-new signup has no shop, and therefore no role and no tenant data.
     * Checkout deliberately PRECEDES shop creation (OnboardingResumeService:
     * STEP_SELECT_PLAN → STEP_PAYMENT → STEP_CREATE_SHOP, and
     * findPendingSubscription() looks for a row with shop_id IS NULL), so the
     * funnel entry points must stay open to them. Nothing tenant-scoped exists
     * to leak: there is no shop.
     */
    public function test_a_shop_less_signup_keeps_the_onboarding_funnel(): void
    {
        $plan = $this->createPlan('manufacturer');
        $user = User::factory()->create([
            'shop_id'              => null,
            'role_id'              => null,
            'is_active'            => true,
            'onboarding_shop_type' => 'manufacturer',
        ]);

        $this->actingAs($user)->get(route('subscription.plans'))->assertOk();
        $this->actingAs($user)
            ->post(route('subscription.choose'), ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertRedirect(route('subscription.payment'));
    }

    /**
     * …and a shop-less user MAY start a trial. Plan selection (including trial)
     * deliberately precedes shop creation — OnboardingResumeService runs
     * STEP_SELECT_PLAN → STEP_PAYMENT → STEP_CREATE_SHOP — and
     * SubscriptionPaymentService::startTrial() already supports it: the term is
     * created with shop_id = null, keyed to the user, and attached to the real
     * shop once shops.store runs. abortUnlessOwnerOrOnboarding() must let this
     * caller through exactly like every sibling commerce route already does;
     * a PROVEN owner or staff member of an EXISTING shop is unaffected and
     * still governed by the coverage above.
     */
    public function test_a_shop_less_signup_can_start_a_trial(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('manufacturer');
        $user = User::factory()->create([
            'shop_id'              => null,
            'role_id'              => null,
            'is_active'            => true,
            'onboarding_shop_type' => 'manufacturer',
        ]);

        $response = $this->actingAs($user)
            ->post(route('subscription.trial.start'), ['plan_id' => $plan->id]);

        $response->assertRedirect(route('shops.create', ['type' => 'manufacturer']));

        $this->assertSame(1, ShopSubscription::count(), 'exactly one trial term is created');

        $subscription = ShopSubscription::first();
        $this->assertNull($subscription->shop_id, 'a shop-less trial has no shop to attach to yet');
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame('trial', $subscription->status);
    }

    /** And they can never see another tenant's billing history. */
    public function test_a_shop_less_signup_sees_no_tenant_invoice(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->subscription($shop, $plan);

        PlatformInvoice::create([
            'shop_id'              => $shop->id,
            'shop_subscription_id' => $sub->id,
            'plan_id'              => $plan->id,
            'invoice_number'       => 'PLT-NOSHOP-LEAK-0001',
            'invoice_sequence'     => 1,
            'billing_cycle'        => 'monthly',
            'billing_period_start' => now()->subMonth()->toDateString(),
            'billing_period_end'   => now()->toDateString(),
            'amount_before_tax'    => 999,
            'gst_rate'             => 18,
            'gst_amount'           => 179.82,
            'total_amount'         => 1178.82,
            'status'               => 'paid',
            'issued_at'            => now(),
            'created_by_admin_id'  => $admin->id,
        ]);

        $stranger = User::factory()->create([
            'shop_id'              => null,
            'role_id'              => null,
            'is_active'            => true,
            'onboarding_shop_type' => 'manufacturer',
        ]);

        $this->actingAs($stranger)
            ->get(route('subscription.status'))
            ->assertDontSee('PLT-NOSHOP-LEAK-0001');
    }

    // ════════════════════════════════════════════════════════════════════════
    // 9 — the machine leg is untouched
    // ════════════════════════════════════════════════════════════════════════

    public function test_the_signed_webhook_stays_unauthenticated_and_unguarded(): void
    {
        $response = $this->postJson(route('subscription.payment.webhook'), ['event' => 'payment.captured']);

        $this->assertNotSame(403, $response->getStatusCode(), 'the owner guard must never reach the webhook');
        $this->assertContains(
            $response->getStatusCode(),
            [400, 500],
            'the webhook must remain unauthenticated and terminate on SIGNATURE verification'
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // 10 — the owner keeps their payment feedback
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Every failure path in paymentCallback() lands on subscription.status, so
     * a 403 there would make refund references and signature errors invisible —
     * money captured with no on-screen trace. The proven owner must still see it.
     */
    public function test_a_proven_owner_still_sees_payment_error_and_refund_references(): void
    {
        [$owner, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan);

        $response = $this->actingAs($owner)
            ->from(route('subscription.payment'))
            ->withSession(['error' => 'Payment failed. Refund reference: rfnd_OWNERVISIBLE1'])
            ->get(route('subscription.status'));

        $this->assertNotSame(403, $response->getStatusCode(), 'the owner must never lose payment feedback');
    }
}
