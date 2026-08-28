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

    // ── 15. administrative holds survive every entitling grant ──────────────

    /**
     * Fixture for a shop the platform administrator has restricted for a reason
     * that has NOTHING to do with billing — compliance review, abuse, a legal
     * hold. `suspended_by` being non-null is the whole discriminator: the
     * subscription lifecycle never writes it, so its presence is proof a human
     * administrator acted.
     */
    private function heldShop(string $mode, PlatformAdmin $admin, string $reason): array
    {
        return $this->shopAndUser('retailer', [
            'access_mode'       => $mode,
            'is_active'         => false,
            'deactivated_at'    => now()->subDays(3),
            'suspended_at'      => now()->subDays(3),
            'suspended_until'   => now()->addDays(10),
            'suspended_by'      => $admin->id,
            'suspension_reason' => $reason,
        ]);
    }

    /**
     * Every entitling status the admin billing flow can write, crossed with both
     * administrative restriction modes. The bug was scoped to `trial` only in the
     * original report, but the controller branch is keyed on $entitling — so
     * `active` and `grace` grants wiped holds identically.
     */
    public static function entitlingStatuses(): array
    {
        return ['trial grant' => ['trial'], 'active grant' => ['active'], 'grace grant' => ['grace']];
    }

    public static function heldGrants(): array
    {
        $cases = [];
        foreach (['read_only', 'suspended'] as $mode) {
            foreach (['trial', 'active', 'grace'] as $status) {
                $cases["admin {$mode} + {$status} grant"] = [$mode, $status];
            }
        }

        return $cases;
    }

    /**
     * POLICY ITEM 15 — the two axes must not leak into each other.
     *
     * A subscription grant is a statement about ENTITLEMENT. An administrative
     * hold is a statement about ACCESS. Recording the former must never retract
     * the latter: the administrator who granted a win-back trial has not
     * reviewed the compliance case, and nothing in the billing form asks them
     * to. The entitlement is recorded and audited; the restriction stands until
     * someone lifts it deliberately through the access-management action.
     *
     * @dataProvider heldGrants
     */
    public function test_administrative_hold_survives_an_admin_entitling_grant(string $mode, string $status): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin  = $this->platformAdmin;
        $reason = 'Administrative hold — compliance review';
        [$shop, $user] = $this->heldShop($mode, $admin, $reason);
        $this->history($shop, $user, 'expired', true);

        $this->grantAsAdmin($admin, $shop, $this->plan(), ['status' => $status])->assertRedirect();

        // The entitlement IS recorded — the grant is not refused, only narrowed.
        $granted = ShopSubscription::where('shop_id', $shop->id)->where('status', $status)->latest('id')->first();
        $this->assertNotNull($granted, "the {$status} grant must still be recorded");
        $event = SubscriptionEvent::where('shop_subscription_id', $granted->id)->latest('id')->first();
        $this->assertNotNull($event, 'the grant must remain audited');
        $this->assertSame($admin->id, $event->admin_id, 'the audit row must attribute the granting administrator');

        // The access axis is untouched — mode, actor AND reason, all verbatim.
        $fresh = $shop->fresh();
        $this->assertSame($mode, $fresh->access_mode, 'the administrative access mode must be preserved');
        $this->assertSame($admin->id, $fresh->suspended_by, 'suspended_by must be preserved — it is the origin proof');
        $this->assertSame($reason, $fresh->suspension_reason, 'the administrative reason must not be overwritten');
        $this->assertFalse((bool) $fresh->is_active, 'a held shop must not be re-enabled by a billing grant');
        $this->assertNotNull($fresh->suspended_at, 'the hold timestamp must survive');
        $this->assertNotNull($fresh->suspended_until, 'a time-boxed hold must keep its expiry — clearing it makes it permanent');
    }

    /**
     * THE FAIL-OPEN TRAP, and the reason this guard tests for positive proof of a
     * subscription-managed lapse instead of merely the absence of an admin actor.
     *
     * The 2026-02-18 control-plane migration backfilled access_mode='suspended'
     * with the reason 'Legacy deactivation migration' for every shop that was
     * already deactivated, and never stamped suspended_by — there was no actor to
     * attribute. Such a row is neither administrative (no actor) nor
     * subscription-managed (the reason does not corroborate a lapse): its origin
     * is simply unknown. A guard keyed on `! suspensionIsAdministrative()` would
     * read "not an admin hold" as "safe to reopen" and hand full access back to a
     * shop somebody deliberately closed.
     *
     * @dataProvider legacyUnattributedRestrictions
     */
    public function test_unattributed_legacy_restriction_is_not_reopened_by_a_grant(string $mode, string $reason): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->platformAdmin;
        [$shop, $user] = $this->shopAndUser('retailer', [
            'access_mode'       => $mode,
            'is_active'         => false,
            'deactivated_at'    => now()->subYear(),
            'suspended_at'      => now()->subYear(),
            'suspended_by'      => null,     // ← no actor: unknown origin, not a lapse
            'suspension_reason' => $reason,
        ]);

        // Precondition: this really is the ambiguous third category.
        $this->assertFalse($shop->suspensionIsAdministrative(), 'fixture must have no admin actor');
        $this->assertFalse($shop->suspensionIsSubscriptionManaged(), 'fixture must not corroborate as a lapse');

        $this->grantAsAdmin($admin, $shop, $this->plan())->assertRedirect();

        $fresh = $shop->fresh();
        $this->assertSame($mode, $fresh->access_mode, 'unknown-origin restrictions must fail closed, not reopen');
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertSame($reason, $fresh->suspension_reason);

        // The entitlement is still recorded — we narrow the grant, never refuse it.
        $this->assertNotNull(ShopSubscription::where('shop_id', $shop->id)->where('status', 'trial')->latest('id')->first());
    }

    public static function legacyUnattributedRestrictions(): array
    {
        return [
            'legacy deactivation migration' => ['suspended', 'Legacy deactivation migration'],
            'legacy missing subscription'   => ['suspended', 'Legacy shop missing subscription record'],
            'unattributed read-only'        => ['read_only', 'Legacy deactivation migration'],
        ];
    }

    /**
     * The other half of the same branch, and the reason it cannot simply be
     * deleted: when the shop is down ONLY because its subscription lapsed, a
     * grant is exactly the thing that should bring it back. suspended_by is
     * null here — the scheduler never stamps it — so the restriction is
     * subscription-managed and self-service recoverable by definition.
     *
     * Provided over all three entitling statuses, mirroring heldGrants: the branch
     * is keyed on $entitling, so recovery must work for every status that reaches
     * it, not just the promotional-trial one the original report named.
     *
     * @dataProvider entitlingStatuses
     */
    public function test_expiry_managed_suspension_is_restored_by_an_admin_entitling_grant(string $status): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->platformAdmin;
        [$shop, $user] = $this->shopAndUser('retailer', [
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'deactivated_at'    => now()->subDays(3),
            'suspended_at'      => now()->subDays(3),
            'suspended_by'      => null,                 // ← lifecycle, not a human
            'suspension_reason' => 'Subscription expired',
        ]);
        $this->history($shop, $user, 'expired', true);

        $this->grantAsAdmin($admin, $shop, $this->plan(), ['status' => $status])->assertRedirect();

        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->access_mode, 'a purely subscription-managed lapse must be recoverable by a grant');
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->suspended_by);
        $this->assertNull($fresh->suspension_reason, 'the subscription-managed reason must be cleared on recovery');
        $this->assertNull($fresh->suspended_at);
        $this->assertNull($fresh->deactivated_at);
    }

    /** An unrestricted, already-active shop simply stays active. */
    public function test_unrestricted_shop_remains_active_after_an_admin_entitling_grant(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->platformAdmin;
        [$shop, $user] = $this->shopAndUser();

        $this->grantAsAdmin($admin, $shop, $this->plan(), ['status' => 'active'])->assertRedirect();

        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->access_mode);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->suspended_by);
    }

    /**
     * Closure is just another administrative restriction wearing different
     * words: the shop is disabled by a human and `suspended_by` records it. A
     * billing grant must not re-open a closed shop, and it must not resurrect
     * the closed owner account either — the billing flow has no business
     * writing to users at all.
     */
    public function test_account_and_shop_closure_are_not_reopened_by_an_admin_entitling_grant(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->platformAdmin;
        [$shop, $user] = $this->heldShop('suspended', $admin, 'Shop closed at owner request');
        $user->forceFill(['is_active' => false])->save();
        $this->history($shop, $user, 'cancelled', true);

        $this->grantAsAdmin($admin, $shop, $this->plan())->assertRedirect();

        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode, 'a closed shop must stay closed');
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertSame($admin->id, $fresh->suspended_by);
        $this->assertSame('Shop closed at owner request', $fresh->suspension_reason);
        $this->assertFalse((bool) $user->fresh()->is_active, 'the billing flow must never re-enable a closed account');
    }

    /**
     * ITEM 7 — the hold is liftable, just not as a side effect. The existing,
     * separately-audited access-management action still does it, which is what
     * keeps "preserve the hold" from meaning "trap the shop forever".
     */
    public function test_administrator_lifts_a_hold_only_through_the_access_management_action(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->platformAdmin;
        [$shop, $user] = $this->heldShop('read_only', $admin, 'Administrative hold — compliance review');

        // Grant first: activation requires a term that covers today, and this is
        // also the ordering a real win-back follows.
        $this->grantAsAdmin($admin, $shop, $this->plan())->assertRedirect();
        $this->assertSame('read_only', $shop->fresh()->access_mode, 'still held after the grant');

        // Now the explicit, separate access decision.
        $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->patch(route('admin.shops.status', $shop), [
                'access_mode' => 'active',
                'reason'      => 'Compliance review closed',
            ])->assertRedirect();

        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->access_mode, 'the supported access action must still be able to lift the hold');
        $this->assertNull($fresh->suspended_by);
    }

    /** A grant on one shop must not disturb another shop's hold. */
    public function test_admin_grant_on_one_shop_leaves_another_shops_hold_intact(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->platformAdmin;
        [$held, $heldUser] = $this->heldShop('suspended', $admin, 'Administrative hold — abuse report');
        [$other, $otherUser] = $this->shopAndUser();

        $this->grantAsAdmin($admin, $other, $this->plan())->assertRedirect();

        $freshHeld = $held->fresh();
        $this->assertSame('suspended', $freshHeld->access_mode);
        $this->assertSame($admin->id, $freshHeld->suspended_by);
        $this->assertSame('Administrative hold — abuse report', $freshHeld->suspension_reason);
        $this->assertSame(0, ShopSubscription::where('shop_id', $held->id)->count(),
            'the grant must not have landed on the wrong tenant');
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
