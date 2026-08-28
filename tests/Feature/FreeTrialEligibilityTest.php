<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * AUTOMATIC FREE-TRIAL ELIGIBILITY — the locked policy.
 *
 * The automatic (self-service) free trial is available ONLY to a genuinely new
 * shop/user with no previous trial AND no previous paid entitlement for that
 * product family. It may be claimed once, ever.
 *
 *   any trial   — active | expired | cancelled  → DENY
 *   any paid    — active | expired | cancelled | historical → DENY
 *   admin hold  — read_only | suspended         → DENY
 *
 * The enforcement kill switch (platform.enforce_subscriptions) decides who
 * BLOCKS a lapsed shop from the ERP. It must have NO bearing on whether that
 * shop can mint itself another free month — otherwise turning enforcement off
 * to keep customers working also hands out free product. Every history-based
 * case below therefore runs under BOTH flag values with identical fixtures, so
 * eligibility is the only variable.
 *
 * The display path and the write path must agree. A hidden card that a crafted
 * POST still honours is not a policy, it is a speed bump — so every "hidden"
 * case is also asserted at the HTTP write boundary, and the administrative
 * cases are asserted at the SERVICE boundary (below any middleware).
 */
class FreeTrialEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private PlatformAdmin $platformAdmin;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        config(['business.subscription_trial_days' => 30]);
        $this->seed(\Database\Seeders\PlatformProductSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);
        // startTrial() stamps updated_by_admin_id from the platform super admin
        // and refuses outright if none exists — without this the "eligible" path
        // would fail for an infrastructure reason and prove nothing about policy.
        $this->platformAdmin = $this->createPlatformAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @return array{0: Shop, 1: User} */
    private function shopAndUser(string $shopType = 'retailer', array $shopAttrs = []): array
    {
        $shop = Shop::create(array_merge([
            'name' => ucfirst($shopType) . ' Shop',
            'shop_type' => $shopType,
            'phone' => fake()->unique()->numerify('9########'),
            'owner_first_name' => 'A',
            'owner_last_name' => 'B',
            'owner_mobile' => fake()->unique()->numerify('9########'),
            'is_active' => true,
            'access_mode' => 'active',
        ], $shopAttrs));

        $user = User::create([
            'name' => 'Owner',
            'mobile_number' => fake()->unique()->numerify('9########'),
            'shop_id' => $shop->id,
            'role_id' => $this->createOwnerRole($shop->id)->id,
            'password' => bcrypt('x'),
            'is_active' => true,
        ]);

        return [$shop, $user];
    }

    private function plan(string $code = 'retailer_yearly'): Plan
    {
        return Plan::where('code', $code)->firstOrFail();
    }

    /**
     * A historical subscription row, written directly. Deliberately NOT via the
     * service: these represent history that already exists, and half of them
     * (paid rows) the service would refuse to create anyway.
     */
    private function history(
        Shop $shop,
        User $user,
        string $status,
        bool $paid,
        string $planCode = 'retailer_yearly'
    ): ShopSubscription {
        $plan = $this->plan($planCode);

        return ShopSubscription::create([
            'shop_id'             => $shop->id,
            'user_id'             => $user->id,
            'plan_id'             => $plan->id,
            'status'              => $status,
            'starts_at'           => now()->subYear(),
            'ends_at'             => now()->subMonth(),
            'grace_ends_at'       => now()->subMonth(),
            'billing_cycle'       => $paid ? 'yearly' : null,
            'price_paid'          => $paid ? 50000 : 0,
            'razorpay_payment_id' => $paid ? 'pay_hist_' . fake()->unique()->numerify('##########') : null,
            'razorpay_order_id'   => $paid ? 'order_hist_' . fake()->unique()->numerify('##########') : null,
            'cancelled_at'        => $status === 'cancelled' ? now()->subMonth() : null,
            'actor_type'          => $paid ? 'self_service' : 'self_service',
        ]);
    }

    private function trialFormMarker(): string
    {
        return route('subscription.trial.start');
    }

    private function getPlans(User $user)
    {
        return $this->actingAs($user)->get(route('subscription.plans'));
    }

    private function postTrial(User $user, ?Plan $plan = null)
    {
        $plan ??= $this->plan();

        return $this->actingAs($user)->post(route('subscription.trial.start'), ['plan_id' => $plan->id]);
    }

    private function trialRowCount(Shop $shop): int
    {
        return ShopSubscription::where('shop_id', $shop->id)->where('status', 'trial')->count();
    }

    /** Enforcement must not change eligibility — every history case runs twice. */
    public static function enforcementModes(): array
    {
        return ['enforcement on' => [true], 'enforcement off' => [false]];
    }

    // ── 1. a genuinely new shop is the ONLY eligible shape ──────────────────

    public function test_new_shop_with_no_entitlement_history_sees_the_free_trial_option(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [, $user] = $this->shopAndUser();

        $this->getPlans($user)
            ->assertOk()
            ->assertSee('Free trial')
            ->assertSee($this->trialFormMarker(), false);
    }

    // ── 2. exactly one, ever ────────────────────────────────────────────────

    public function test_new_shop_may_start_exactly_one_automatic_trial(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$shop, $user] = $this->shopAndUser();

        $this->postTrial($user)->assertRedirect();
        $this->assertSame(1, $this->trialRowCount($shop), 'first claim creates the trial');

        // Second claim: refused. It redirects (to the status page — the owner is
        // mid-trial, so the controller sends them to look at it) rather than
        // erroring, so the assertion that carries the policy is the row count.
        $this->postTrial($user)->assertRedirect();
        $this->assertSame(1, $this->trialRowCount($shop), 'a second trial must never be created');
    }

    // ── 3-7. prior entitlement, under BOTH enforcement values (item 8) ──────

    /**
     * @dataProvider enforcementModes
     */
    public function test_active_trial_hides_and_rejects_another_trial(bool $enforce): void
    {
        config(['platform.enforce_subscriptions' => $enforce]);
        [$shop, $user] = $this->shopAndUser();
        $live = $this->history($shop, $user, 'trial', false);
        $live->forceFill(['starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29), 'grace_ends_at' => now()->addDays(29)])->save();

        $this->getPlans($user)->assertOk()->assertDontSee($this->trialFormMarker(), false);
        $this->postTrial($user)->assertSessionHas('error');
        $this->assertSame(1, $this->trialRowCount($shop));
    }

    /**
     * @dataProvider enforcementModes
     */
    public function test_expired_trial_hides_and_rejects_another_trial(bool $enforce): void
    {
        config(['platform.enforce_subscriptions' => $enforce]);
        [$shop, $user] = $this->shopAndUser();
        $this->history($shop, $user, 'expired', false);

        $this->getPlans($user)->assertOk()->assertDontSee($this->trialFormMarker(), false);
        $this->postTrial($user)->assertSessionHas('error');
        $this->assertSame(0, $this->trialRowCount($shop), 'an expired trial must not be re-minted');
    }

    /**
     * @dataProvider enforcementModes
     */
    public function test_cancelled_trial_hides_and_rejects_another_trial(bool $enforce): void
    {
        config(['platform.enforce_subscriptions' => $enforce]);
        [$shop, $user] = $this->shopAndUser();
        $this->history($shop, $user, 'cancelled', false);

        $this->getPlans($user)->assertOk()->assertDontSee($this->trialFormMarker(), false);
        $this->postTrial($user)->assertSessionHas('error');
        $this->assertSame(0, $this->trialRowCount($shop), 'cancelling a trial must not restore eligibility');
    }

    /**
     * The revenue hole this suite exists for: a shop that PAID, never trialled,
     * and has now lapsed. Nothing in its history has price_paid = 0, so a
     * trial-only predicate sees a virgin account and hands it a free month.
     *
     * @dataProvider enforcementModes
     */
    public function test_previously_paid_never_trialled_now_expired_hides_and_rejects_trial(bool $enforce): void
    {
        config(['platform.enforce_subscriptions' => $enforce]);
        [$shop, $user] = $this->shopAndUser();
        $this->history($shop, $user, 'expired', true);

        $this->assertSame(0, ShopSubscription::where('shop_id', $shop->id)->where('price_paid', 0)->count(),
            'fixture: no free row exists — a trial-only predicate would see nothing');

        $this->getPlans($user)->assertOk()->assertDontSee($this->trialFormMarker(), false);
        $this->postTrial($user)->assertSessionHas('error');
        $this->assertSame(0, $this->trialRowCount($shop), 'a lapsed paying customer is not a new customer');
    }

    /**
     * @dataProvider enforcementModes
     */
    public function test_previously_paid_and_cancelled_hides_and_rejects_trial(bool $enforce): void
    {
        config(['platform.enforce_subscriptions' => $enforce]);
        [$shop, $user] = $this->shopAndUser();
        $this->history($shop, $user, 'cancelled', true);

        $this->getPlans($user)->assertOk()->assertDontSee($this->trialFormMarker(), false);
        $this->postTrial($user)->assertSessionHas('error');
        $this->assertSame(0, $this->trialRowCount($shop));
    }

    // ── 9. the write boundary alone, for every ineligible shape ─────────────

    public static function ineligibleHistories(): array
    {
        return [
            'active trial'     => ['trial', false],
            'expired trial'    => ['expired', false],
            'cancelled trial'  => ['cancelled', false],
            'expired paid'     => ['expired', true],
            'cancelled paid'   => ['cancelled', true],
            'historical paid'  => ['active', true],
        ];
    }

    /**
     * @dataProvider ineligibleHistories
     */
    public function test_direct_post_to_trial_start_is_rejected_for_every_ineligible_state(string $status, bool $paid): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$shop, $user] = $this->shopAndUser();
        $this->history($shop, $user, $status, $paid);

        $before = ShopSubscription::where('shop_id', $shop->id)->count();
        $this->postTrial($user)->assertRedirect();
        $this->assertSame($before, ShopSubscription::where('shop_id', $shop->id)->count(),
            'a direct POST must not create a subscription for an ineligible shop');
    }

    // ── 10-11. administrative axis, asserted BELOW the middleware ───────────

    public function test_administrator_read_only_is_rejected_at_the_service_boundary(): void
    {
        config(['platform.enforce_subscriptions' => false]); // middleware disarmed on purpose
        $admin = $this->platformAdmin;
        [$shop, $user] = $this->shopAndUser('retailer', [
            'access_mode'      => 'read_only',
            'suspended_by'     => $admin->id,
            'suspension_reason' => 'Administrative hold',
        ]);
        $this->actingAs($user);

        $this->expectException(LogicException::class);
        try {
            app(SubscriptionPaymentService::class)->startTrial($this->plan());
        } finally {
            $this->assertSame(0, $this->trialRowCount($shop),
                'a self-service trial must never be minted under an administrative hold');
        }
    }

    public function test_administrator_suspension_is_rejected_at_the_service_boundary(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        $admin = $this->platformAdmin;
        [$shop, $user] = $this->shopAndUser('retailer', [
            'access_mode'      => 'suspended',
            'suspended_by'     => $admin->id,
            'suspension_reason' => 'Administrative hold',
        ]);
        $this->actingAs($user);

        $this->expectException(LogicException::class);
        try {
            app(SubscriptionPaymentService::class)->startTrial($this->plan());
        } finally {
            $this->assertSame(0, $this->trialRowCount($shop));
        }
    }

    /**
     * Regression companion to the two above: a shop suspended by the LAPSE
     * (suspended_by NULL — the entitlement axis, not an admin act) must still be
     * refused a trial. This is the case the pre-existing literal `access_mode
     * === 'suspended'` check covered, and tightening to an origin-aware
     * predicate must not drop it.
     */
    public function test_subscription_managed_suspension_is_also_rejected(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$shop, $user] = $this->shopAndUser('retailer', [
            'access_mode'       => 'suspended',
            'suspended_by'      => null,
            'suspension_reason' => 'Subscription expired',
        ]);
        $this->actingAs($user);

        $this->expectException(LogicException::class);
        try {
            app(SubscriptionPaymentService::class)->startTrial($this->plan());
        } finally {
            $this->assertSame(0, $this->trialRowCount($shop));
        }
    }

    // ── 12. denying the trial must not deny the sale ────────────────────────

    public function test_paid_renewal_plans_remain_visible_and_usable_for_an_expired_owner(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$shop, $user] = $this->shopAndUser();
        $this->history($shop, $user, 'expired', true);

        $yearly = $this->plan('retailer_yearly');

        $this->getPlans($user)
            ->assertOk()
            ->assertDontSee($this->trialFormMarker(), false)
            ->assertSee(route('subscription.choose'), false)
            ->assertSee('value="' . $yearly->id . '"', false);

        // …and the checkout path is genuinely reachable, not just rendered.
        $this->actingAs($user)
            ->post(route('subscription.choose'), ['plan_id' => $yearly->id, 'billing_cycle' => 'yearly'])
            ->assertRedirect(route('subscription.payment'));
    }

    // ── 13. family + cross-shop isolation ───────────────────────────────────

    public function test_family_and_cross_shop_isolation_remain_correct(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $svc = app(SubscriptionPaymentService::class);

        [$shopA, $userA] = $this->shopAndUser();
        $this->history($shopA, $userA, 'expired', true, 'retailer_yearly');

        // Same family, different edition → still denied (retailer+manufacturer = 'erp').
        $this->assertTrue($svc->hasPriorEntitlementForFamily($shopA->id, \App\Support\ShopEdition::MANUFACTURER, $userA->id),
            'retailer history must block the manufacturer trial — one family');

        // Different family → unaffected.
        $this->assertFalse($svc->hasPriorEntitlementForFamily($shopA->id, 'dhiran', $userA->id),
            'an ERP subscription must not consume the Dhiran trial');

        // Another tenant entirely → unaffected.
        [$shopB, $userB] = $this->shopAndUser();
        $this->assertFalse($svc->hasPriorEntitlementForFamily($shopB->id, \App\Support\ShopEdition::RETAILER, $userB->id),
            'one shop\'s history must never consume another shop\'s trial');

        $this->getPlans($userB)->assertOk()->assertSee($this->trialFormMarker(), false);
    }

    // ── 14-16. the administrator promotional (win-back) grant ───────────────

    private function grantAsAdmin(PlatformAdmin $admin, Shop $shop, Plan $plan, array $overrides = [])
    {
        return $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->patch(route('admin.shops.subscription', $shop), array_merge([
                'plan_id'       => $plan->id,
                'status'        => 'trial',
                'billing_cycle' => 'yearly',
                'starts_at'     => now()->toDateString(),
                'ends_at'       => now()->addDays(30)->toDateString(),
                'reason'        => 'Promotional win-back trial',
            ], $overrides));
    }

    /**
     * The promotional trial is a DIFFERENT instrument from the automatic one:
     * an administrator may deliberately grant one to win a lapsed customer back.
     * Closing the public route must not close this one — and it must stay
     * audited, because a grant nobody can attribute is a grant nobody can revoke.
     */
    public function test_admin_promotional_grant_remains_possible_and_audited(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->platformAdmin;
        [$shop, $user] = $this->shopAndUser();
        $this->history($shop, $user, 'expired', true);

        $this->grantAsAdmin($admin, $shop, $this->plan())->assertRedirect();

        $granted = ShopSubscription::where('shop_id', $shop->id)->where('status', 'trial')->latest('id')->first();
        $this->assertNotNull($granted, 'the administrator must still be able to grant a promotional trial');

        $event = SubscriptionEvent::where('shop_subscription_id', $granted->id)->latest('id')->first();
        $this->assertNotNull($event, 'the grant must be audited');
        $this->assertSame($admin->id, $event->admin_id, 'the audit row must attribute the granting administrator');
        $this->assertStringContainsString('Promotional win-back', (string) $event->reason);
    }

    /**
     * POLICY ITEM 15 — administrative hold must survive a promotional grant.
     *
     * DIVERGENCE, REPORTED NOT PATCHED. BillingManagementController treats every
     * entitling status (trial|active|grace) as a reason to write the shop back
     * to access_mode='active', suspended_by=null — so granting a promotional
     * trial to an administratively-held shop silently lifts the hold. Correcting
     * that requires editing BillingManagementController, which this task's brief
     * explicitly gates behind a STOP-and-report.
     *
     * This test therefore PINS THE CURRENT BEHAVIOUR rather than the policy, so
     * that (a) the divergence is documented in the suite itself and (b) whoever
     * fixes it gets a failing test the moment they do. The policy assertion it
     * should eventually make is spelled out inline.
     */
    public function test_admin_promotional_grant_does_not_clear_an_administrative_hold(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->platformAdmin;
        [$shop, $user] = $this->shopAndUser('retailer', [
            'access_mode'       => 'read_only',
            'suspended_by'      => $admin->id,
            'suspension_reason' => 'Administrative hold — compliance review',
        ]);
        $this->history($shop, $user, 'expired', true);

        $this->grantAsAdmin($admin, $shop, $this->plan())->assertRedirect();

        $fresh = $shop->fresh();

        // POLICY (not yet enforced — needs BillingManagementController):
        //   $this->assertSame('read_only', $fresh->access_mode);
        //   $this->assertNotNull($fresh->suspended_by);
        //
        // CHARACTERISATION of what the shipped controller actually does today:
        $this->assertSame('active', $fresh->access_mode,
            'CHARACTERISATION: the entitling-status branch currently reactivates the shop');
        $this->assertNull($fresh->suspended_by,
            'CHARACTERISATION: the administrative hold is currently cleared by an entitling grant. '
            . 'See BillingManagementController::updateShopSubscription() — reported, not patched.');

        // Whatever the controller does to the shop, the grant must remain
        // attributable. That part of policy 15 IS enforceable here.
        $granted = ShopSubscription::where('shop_id', $shop->id)->where('status', 'trial')->latest('id')->first();
        $this->assertNotNull($granted);
        $this->assertNotNull(SubscriptionEvent::where('shop_subscription_id', $granted->id)->first());
    }

    /**
     * A promotional grant is a one-off gift from an administrator. It must not
     * re-open the public self-service route — otherwise every win-back also
     * hands the owner a second free month they can take on their own.
     */
    public function test_public_free_trial_card_remains_hidden_after_an_admin_promotional_grant(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->platformAdmin;
        [$shop, $user] = $this->shopAndUser();
        $this->history($shop, $user, 'expired', true);

        $this->grantAsAdmin($admin, $shop, $this->plan())->assertRedirect();

        $this->getPlans($user)->assertOk()->assertDontSee($this->trialFormMarker(), false);

        // And the write path agrees: no self-service top-up on top of the gift.
        $before = ShopSubscription::where('shop_id', $shop->id)->count();
        $this->postTrial($user)->assertRedirect();
        $this->assertSame($before, ShopSubscription::where('shop_id', $shop->id)->count());
    }
}
