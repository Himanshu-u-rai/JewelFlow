<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * WITHDRAWAL OF A WRONG DIAGNOSIS, PINNED SO IT CANNOT BE RE-ASSERTED.
 *
 * A review claimed that a shop in access_mode = 'read_only' could reach the
 * plan picker but not act on it — "GET subscription.plans succeeds, POST
 * subscription.choose is refused with 423" — reasoning from two facts that are
 * individually true:
 *
 *   1. EnsureAccountIsActive refuses a non-GET when access_mode is read_only
 *      without consulting the lapse classifier (the branch is real).
 *   2. The subscription routes are declared with 'account.active' but NOT
 *      'subscription.active', so the classifier-aware sibling middleware never
 *      runs on them.
 *
 * The conclusion does not follow, because EnsureAccountIsActive opens with a
 * routeIs() BYPASS LIST that returns $next($request) before access_mode is
 * read at all — and subscription.plans, subscription.choose, subscription.payment,
 * subscription.payment.initiate, subscription.payment.callback and
 * subscription.status are all on it. Middleware-group ordering cannot override
 * an early return inside the middleware itself.
 *
 * Reading a middleware from its interesting branch outward is how the mistake
 * was made; the guard clauses at the top decide reachability. So this file
 * asserts on the ROUTES BY NAME through the real HTTP stack rather than on any
 * middleware in isolation:
 *
 *   corroborated read_only lapse → plans → choose(valid plan) → payment page.
 *
 * The two exemptions that must NOT widen are pinned alongside it:
 *   • subscription.trial.start is deliberately absent from the bypass list — a
 *     free trial force-activates the shop, so bypassing it would let a hold be
 *     lifted for free. It must still be refused.
 *   • an ordinary ERP write must still be refused, or the bypass list has
 *     stopped being a list.
 *
 * No payment is initiated anywhere in this file; the journey stops at the
 * payment PAGE, which is the last step before money.
 */
class SubscriptionRecoveryRouteReachabilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNotPostgres();

        // Enforcement ON. With it off, EnsureSubscriptionIsActive heals a lapse
        // back to active on the first page view, and the journey would pass for
        // the wrong reason — the shop would no longer be read_only by the time
        // the POST is made.
        config(['platform.enforce_subscriptions' => true]);

        // 'account.active' only — the same declaration the real subscription
        // routes carry (routes/web.php), so the probe measures exactly the
        // middleware under discussion and nothing stacked around it.
        Route::middleware(['web', 'auth', 'tenant', 'account.active'])
            ->group(function () {
                Route::post('/_probe/write', fn () => response('ok'))->name('probe.write');
            });
    }

    /** @return array{0: User, 1: Shop, 2: Plan, 3: PlatformAdmin} */
    private function tenant(): array
    {
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        $shop = $this->createShop('retailer');
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $this->createBillingSettings($shop->id);
        $this->markShopOpeningSetupComplete($shop->id);

        return [$user, $shop, $plan, $admin];
    }

    /**
     * The four-component JF-0001 incident: read_only access, no administrator
     * attribution, and a corroborating read_only subscription row with no live
     * term behind it. Shop::suspensionIsSubscriptionManaged() returns true only
     * for this exact shape.
     */
    private function corroboratedLapse(Shop $shop, Plan $plan): void
    {
        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'read_only',
            'starts_at' => now()->subMonths(14)->toDateString(),
            'ends_at' => now()->subDays(20)->toDateString(),
        ]);

        $shop->forceFill([
            'access_mode' => 'read_only',
            'is_active' => false,
            'suspended_at' => now()->subDays(3),
            'suspension_reason' => null,
            'suspended_by' => null,
            'suspended_until' => null,
        ])->save();
    }

    // ════════════════════════════════════════════════════════════════════
    // The withdrawal: the recovery journey is reachable end to end
    // ════════════════════════════════════════════════════════════════════

    public function test_a_read_only_lapse_walks_plans_to_choose_to_the_payment_page(): void
    {
        [$user, $shop, $plan] = $this->tenant();
        $this->corroboratedLapse($shop, $plan);

        $this->assertSame('read_only', $shop->fresh()->access_mode);
        $this->assertTrue($shop->fresh()->suspensionIsSubscriptionManaged());

        // 1. The plan picker.
        $this->actingAs($user)
            ->get(route('subscription.plans'))
            ->assertOk();

        // 2. The POST the review said would be refused with 423.
        //
        // 'monthly' deliberately: the shared createPlan() fixture sets
        // price_monthly and leaves price_yearly null, and payment() bounces a
        // priceless cycle back to the picker. Choosing 'yearly' here would fail
        // this test for a fixture reason that has nothing to do with the
        // middleware question being asked.
        $choose = $this->actingAs($user)->post(route('subscription.choose'), [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ]);

        $this->assertNotSame(
            Response::HTTP_LOCKED,
            $choose->getStatusCode(),
            'subscription.choose is on the EnsureAccountIsActive bypass list; a 423 here would mean the bypass regressed.'
        );
        $choose->assertRedirect(route('subscription.payment'));

        // 3. The payment page — the last step before money changes hands.
        $this->actingAs($user)
            ->get(route('subscription.payment'))
            ->assertOk();

        // The shop is still restricted: reaching the checkout is not the same
        // as being restored, and nothing in this journey may restore it.
        $this->assertSame('read_only', $shop->fresh()->access_mode);
    }

    public function test_the_shop_is_still_read_only_after_walking_the_journey(): void
    {
        [$user, $shop, $plan] = $this->tenant();
        $this->corroboratedLapse($shop, $plan);

        $this->actingAs($user)->get(route('subscription.plans'))->assertOk();
        $this->actingAs($user)->post(route('subscription.choose'), [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ]);

        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode);
        $this->assertNull($fresh->suspended_by, 'Nothing in the recovery journey may invent an administrator stamp.');
    }

    // ════════════════════════════════════════════════════════════════════
    // The exemptions must not widen
    // ════════════════════════════════════════════════════════════════════

    /**
     * The bypass list is a list, not a blanket. An ordinary ERP write from the
     * same read_only shop must still be refused — otherwise the read-only
     * contract that Masters and Historical depend on is gone.
     */
    public function test_an_ordinary_erp_write_is_still_refused_for_the_same_shop(): void
    {
        [$user, $shop, $plan] = $this->tenant();
        $this->corroboratedLapse($shop, $plan);

        // A disposable probe route rather than a real ERP endpoint, matching
        // LegacyReadOnlyCorroborationTest. A real endpoint would need its own
        // parent fixtures, and route-model binding would answer 404 before the
        // middleware ever rendered a verdict — which is how the first draft of
        // this test failed, measuring the binding instead of the gate.
        $this->actingAs($user)
            ->postJson('/_probe/write')
            ->assertStatus(Response::HTTP_LOCKED);
    }

    /**
     * subscription.trial.start is deliberately NOT bypassed (see the comment on
     * the bypass list). A free trial force-activates the shop, so bypassing it
     * would hand every restricted shop a free way out.
     *
     * A VALID payload is submitted on purpose. An earlier cut posted nothing at
     * all, which meant a mere `required` validation bounce on plan_id would have
     * produced the same "no trial row, still read_only" end state — a validation
     * failure masquerading as authorization coverage. With a real plan_id the only
     * thing left that can refuse this request is the middleware, and the
     * assertions below name that refusal positively rather than infer it from an
     * unchanged database.
     *
     * The refusal is a 302 `back()`, NOT a 423. EnsureAccountIsActive::deny()
     * negotiates on content type: JSON and `api/*` get
     * `response()->json(..., 423)`, a browser form POST gets
     * `back()->withErrors(['shop' => ...])`. The /_probe/write test above is the
     * JSON leg of this same guard; this is its web leg.
     */
    public function test_the_trial_route_is_not_bypassed(): void
    {
        [$user, $shop, $plan] = $this->tenant();
        $this->corroboratedLapse($shop, $plan);

        // from() makes back() deterministic. Without it the bounce resolves to '/'
        // and the redirect target proves nothing about what was refused.
        $response = $this->actingAs($user)
            ->from(route('subscription.plans'))
            ->post(route('subscription.trial.start'), ['plan_id' => $plan->id]);

        $response->assertRedirect(route('subscription.plans'));
        $response->assertSessionHasErrors([
            'shop' => 'Shop is in read-only mode. Writes are blocked by platform policy.',
        ]);

        // THE DISCRIMINATOR: a validation failure would land under 'plan_id'. The
        // read-only refusal has to be the reason, and the only reason.
        $response->assertSessionDoesntHaveErrors(['plan_id']);

        // startTrial()'s body never ran, so neither marker it would set exists.
        $response->assertSessionMissing('pending_subscription_id');
        $response->assertSessionMissing('subscription_completed');

        $this->assertSame('read_only', $shop->fresh()->access_mode, 'A restricted shop must not be able to trial its way out.');
        $this->assertDatabaseMissing('shop_subscriptions', [
            'shop_id' => $shop->id,
            'status' => 'trial',
        ]);
    }

    // ════════════════════════════════════════════════════════════════════
    // Administrative holds and non-owners stay enforced
    // ════════════════════════════════════════════════════════════════════

    /**
     * An administrator hold carries suspended_by, so the classifier refuses it
     * and ShopSubscription::blocksNewPaidTerm() refuses the purchase. Note the
     * refusal comes from the CONTROLLER, not from the middleware — the route is
     * bypassed either way. That distinction is the whole point of asking where
     * a refusal comes from before proposing an authorization change.
     *
     * WHY THIS TEST HAD TO BE REWRITTEN. The first cut asserted only that the shop
     * row and the subscription rows were unchanged, and ignored the response
     * entirely. Those assertions cannot fail: choosePlan() does not activate a
     * subscription even on the SUCCESS path — it writes two session keys and
     * redirects to the payment page. So "shop still read_only, no active row"
     * describes an ACCEPTED plan selection just as well as a refused one, and the
     * test would have passed with the guard deleted.
     *
     * The real refusal is `purchaseBlockedResponse()`: because
     * suspensionIsAdministrative() is true AND access_mode is read_only, it
     * returns `redirect()->route('dashboard')->with('error', ...)`. And the real
     * evidence that checkout never opened is the ABSENCE of the pending_plan_id /
     * pending_billing_cycle session pair, which is the only thing choosePlan()
     * writes before handing off. Both are asserted below.
     */
    public function test_an_administrator_hold_cannot_buy_its_way_out(): void
    {
        [$user, $shop, $plan, $admin] = $this->tenant();
        $shop->forceFill([
            'access_mode' => 'read_only',
            'is_active' => false,
            'suspended_at' => now()->subDay(),
            'suspension_reason' => 'Compliance hold',
            'suspended_by' => $admin->id,
        ])->save();

        $this->assertFalse($shop->fresh()->suspensionIsSubscriptionManaged());
        $this->assertTrue(
            $shop->fresh()->suspensionIsAdministrative(),
            'Fixture guard: without an administrative classification blocksNewPaidTerm() would not fire and this test would measure nothing.'
        );

        // 'monthly', not 'yearly'. createPlan() prices price_monthly and leaves
        // price_yearly null, so a yearly cycle could be bounced by payment()'s
        // priceless-cycle guard further down the funnel — a second possible reason
        // for the same visible outcome. A fully valid, priced request leaves the
        // administrative hold as the only thing that can refuse it.
        $response = $this->actingAs($user)->post(route('subscription.choose'), [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas(
            'error',
            'Your shop is under an administrative hold by JewelFlows. A subscription purchase cannot lift it — please contact support.'
        );

        // Checkout never opened. These two keys are the whole of choosePlan()'s
        // forward progress, so their absence is the positive proof of refusal that
        // the unchanged rows below cannot supply.
        $response->assertSessionMissing('pending_plan_id');
        $response->assertSessionMissing('pending_billing_cycle');

        $this->assertSame('read_only', $shop->fresh()->access_mode);
        $this->assertSame($admin->id, (int) $shop->fresh()->suspended_by, 'The hold must survive a purchase attempt.');
        $this->assertDatabaseMissing('shop_subscriptions', [
            'shop_id' => $shop->id,
            'status' => 'active',
        ]);
    }

    /*
     * NON-OWNER RESTRICTIONS are deliberately not re-tested here.
     *
     * choosePlan() opens with abortUnlessOwnerOrOnboarding(), and that refusal
     * is already pinned in depth by OwnerIdentityFailClosedTest
     * (test_proven_staff_are_denied_every_subscription_route,
     * test_a_role_less_user_is_denied_every_subscription_route,
     * test_exact_owner_mobile_equality_is_not_accepted_as_proof_of_ownership)
     * and OwnerOnlySubscriptionCommerceTest
     * (test_staff_are_denied_every_owner_commerce_route). Those suites build
     * the staff fixtures properly; a thinner copy here would be a worse test of
     * the same rule.
     *
     * They are run alongside this file as part of the focused regression set,
     * for the reason this file exists: the bypass list gets a request PAST the
     * middleware, so the controller's own authorization is the only thing left
     * standing between staff and a purchase. Widening the bypass without
     * re-running them would be exactly the mistake this file documents.
     */
}
