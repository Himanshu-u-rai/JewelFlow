<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use App\Support\SubscriptionRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * SUBSCRIPTION RECOVERY — CLOSURE CORRECTION.
 *
 * The lifecycle fix (f078cab) unblocked the owner login loop but left three
 * enforce-independent traps. This matrix proves the corrections:
 *
 *  1. Enforcement flag semantics: enforce=off → the nightly scheduler may TRACK
 *     subscription status but must NEVER lock ERP access; enforce=on → a lapse
 *     downgrades the shop. Under both flags an ADMIN suspension is untouched.
 *  2. Admin suspension is not bypassable by payment: checkout is blocked, and a
 *     settled payment records the money but never lifts the admin lock.
 *  3. Concurrency / superseded rows: an expiry pass can never re-suspend a shop
 *     that a newer live subscription (e.g. a fresh payment) already covers.
 *  4. Classifier precedence: suspended_by (an admin FK) overrides ANY reason
 *     string — an admin re-suspension that still reads "Subscription expired"
 *     is administrative, not recoverable.
 *  5. Account middleware coverage + distinct admin API code.
 *  6. isShopOwner ownership validation (cross-shop role cannot grant recovery).
 */
class SubscriptionRecoveryCorrectionTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function ownerFor(Shop $shop): User
    {
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $user->forceFill(['realm' => 'erp', 'password' => Hash::make('password')])->save();

        return $user;
    }

    /** Subscription-managed lock: recoverable, suspended_by is null. */
    private function subLock(Shop $shop, string $reason = 'Subscription expired'): Shop
    {
        $shop->forceFill([
            'access_mode' => 'suspended',
            'is_active' => false,
            'suspended_by' => null,
            'suspension_reason' => $reason,
        ])->save();

        return $shop;
    }

    /** Administrative lock: Contact-Support, suspended_by set to a real admin. */
    private function adminLock(Shop $shop, PlatformAdmin $admin, string $reason = 'Fraud investigation'): Shop
    {
        $shop->forceFill([
            'access_mode' => 'suspended',
            'is_active' => false,
            'suspended_by' => $admin->id,
            'suspension_reason' => $reason,
        ])->save();

        return $shop;
    }

    private function sub(Shop $shop, Plan $plan, string $status, string $ends, string $grace): ShopSubscription
    {
        return ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => $ends,
            'grace_ends_at' => $grace,
        ]);
    }

    /** A plan that suspends (not read-only) on a full lapse. */
    private function hardLapsePlan(): Plan
    {
        $plan = $this->createPlan('retailer');
        $plan->forceFill(['downgrade_to_read_only_on_due' => false])->save();

        return $plan;
    }

    // ── 4. Classifier precedence (bypass defence) ──────────────────────────

    public function test_admin_fk_overrides_a_subscription_looking_reason(): void
    {
        $admin = $this->createPlatformAdmin();
        $shop = $this->createShop('retailer');

        // Same reason text, differing only by the admin FK.
        $shop->forceFill(['suspension_reason' => 'Subscription expired', 'suspended_by' => null]);
        $this->assertTrue($shop->suspensionIsSubscriptionManaged(), 'no admin FK → recoverable');
        $this->assertFalse($shop->suspensionIsAdministrative());

        $shop->forceFill(['suspended_by' => $admin->id]);
        $this->assertTrue($shop->suspensionIsAdministrative(), 'admin FK → administrative');
        $this->assertFalse(
            $shop->suspensionIsSubscriptionManaged(),
            'an admin re-suspension that still reads "Subscription…" must NOT be recoverable'
        );
    }

    // ── 1. Enforcement flag: scheduler ─────────────────────────────────────

    public function test_scheduler_tracks_status_but_does_not_lock_shop_when_enforcement_off(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        $plan = $this->createPlan('retailer'); // grace_days > 0
        $shop = $this->createShop('retailer'); // active
        // Term ended yesterday, grace still open → status should track to grace.
        $this->sub($shop, $plan, 'active', now()->subDay()->toDateString(), now()->addDays(3)->toDateString());

        Artisan::call('subscription:check-expiry');

        $this->assertSame('grace', ShopSubscription::where('shop_id', $shop->id)->value('status'), 'status is tracked');
        $shop->refresh();
        $this->assertSame('active', $shop->access_mode, 'enforce=off must NOT lock ERP access');
        $this->assertTrue((bool) $shop->is_active);
    }

    public function test_scheduler_locks_shop_when_enforcement_on(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $plan = $this->hardLapsePlan();
        $shop = $this->createShop('retailer');
        // Term AND grace fully past → full lapse → suspended.
        $this->sub($shop, $plan, 'active', now()->subDays(10)->toDateString(), now()->subDays(3)->toDateString());

        Artisan::call('subscription:check-expiry');

        $shop->refresh();
        $this->assertSame('suspended', $shop->access_mode);
        $this->assertFalse((bool) $shop->is_active);
        $this->assertSame('expired', ShopSubscription::where('shop_id', $shop->id)->value('status'));
    }

    public function test_scheduler_never_overrides_an_admin_suspension(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->createPlatformAdmin();
        $plan = $this->hardLapsePlan();
        $shop = $this->createShop('retailer');
        $this->adminLock($shop, $admin, 'Fraud investigation');
        $this->sub($shop, $plan, 'active', now()->subDays(10)->toDateString(), now()->subDays(3)->toDateString());

        Artisan::call('subscription:check-expiry');

        $shop->refresh();
        // Admin lock survives intact — reason not overwritten, FK not cleared.
        $this->assertSame($admin->id, $shop->suspended_by);
        $this->assertSame('Fraud investigation', $shop->suspension_reason);
    }

    // ── 3. Concurrency / superseded row ────────────────────────────────────

    public function test_expiry_cannot_resuspend_a_shop_covered_by_a_newer_live_subscription(): void
    {
        // Models a freshly-paid shop: the new active term outranks the lapsing
        // trial. The expiry pass must transition the old row's bookkeeping ONLY
        // and leave the shop active. The row lock in applyShopModeUnderLock
        // serialises this against a concurrent payment; the outcome asserted
        // here is exactly what that lock+guard guarantee.
        config(['platform.enforce_subscriptions' => true]);
        $plan = $this->hardLapsePlan();
        $shop = $this->createShop('retailer'); // active

        $old = $this->sub($shop, $plan, 'trial', now()->subDays(10)->toDateString(), now()->subDays(3)->toDateString());
        $new = $this->sub($shop, $plan, 'active', now()->addMonth()->toDateString(), now()->addDays(35)->toDateString());
        $this->assertGreaterThan($old->id, $new->id, 'newer row must have a higher id');

        Artisan::call('subscription:check-expiry');

        $shop->refresh();
        $this->assertSame('active', $shop->access_mode, 'newer live term keeps the shop running');
        $this->assertTrue((bool) $shop->is_active);
        $this->assertSame('expired', $old->fresh()->status, 'old row is transitioned as bookkeeping');
        $this->assertSame('active', $new->fresh()->status, 'newer row untouched');
    }

    // ── 2. Payment cannot bypass an admin suspension ───────────────────────

    public function test_settled_payment_records_money_but_does_not_lift_admin_suspension(): void
    {
        $admin = $this->createPlatformAdmin();          // also the systemAdmin() for the receipt
        $plan = $this->createPlan('retailer');
        $shop = $this->createShop('retailer');
        $this->adminLock($shop, $admin, 'Chargeback fraud');
        $owner = $this->ownerFor($shop);
        $this->actingAs($owner);

        $payId = 'pay_' . uniqid();
        $subscription = app(SubscriptionPaymentService::class)
            ->createSubscription($plan, 'monthly', 999.0, $payId, 'order_' . uniqid());

        // Money is recorded…
        $this->assertSame($payId, $subscription->razorpay_payment_id);
        // …but the admin lock is untouched.
        $shop->refresh();
        $this->assertSame('suspended', $shop->access_mode, 'payment must NOT reactivate an admin-suspended shop');
        $this->assertFalse((bool) $shop->is_active);
        $this->assertSame($admin->id, $shop->suspended_by);
    }

    public function test_settled_payment_reactivates_a_subscription_managed_lock(): void
    {
        $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        $shop = $this->createShop('retailer');
        $this->subLock($shop, 'Subscription expired'); // recoverable, no admin FK
        $owner = $this->ownerFor($shop);
        $this->actingAs($owner);

        app(SubscriptionPaymentService::class)
            ->createSubscription($plan, 'monthly', 999.0, 'pay_' . uniqid(), 'order_' . uniqid());

        $shop->refresh();
        $this->assertSame('active', $shop->access_mode, 'a recoverable lock IS cleared by payment');
        $this->assertTrue((bool) $shop->is_active);
    }

    public function test_admin_suspended_shop_is_blocked_from_starting_checkout(): void
    {
        // Real HTTP stack: initiatePayment is bypass-listed by both middlewares,
        // so the controller is the true server-side boundary for the admin case.
        $admin = $this->createPlatformAdmin();
        $shop = $this->createShop('retailer');
        $this->adminLock($shop, $admin);
        $owner = $this->ownerFor($shop);

        $res = $this->actingAs($owner)->postJson(route('subscription.payment.initiate'));

        $res->assertStatus(403);
        $res->assertJsonFragment(['redirect' => route('subscription.status')]);
    }

    // ── 5. Account middleware coverage + distinct admin code ───────────────

    private function runAccountMiddleware(User $user, Request $request)
    {
        $this->actingAs($user);
        $request->setLaravelSession(app('session.store'));

        return app(EnsureAccountIsActive::class)->handle($request, fn ($r) => response('ok'));
    }

    public function test_account_middleware_recovers_a_subscription_lapse_for_the_owner(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $shop = $this->createShop('retailer');
        $this->subLock($shop);
        $owner = $this->ownerFor($shop);

        $response = $this->runAccountMiddleware($owner, Request::create('/dashboard', 'GET'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString(route('subscription.plans'), $response->headers->get('Location'));
        $this->assertTrue(Auth::check(), 'recovery must not log the owner out');
    }

    public function test_account_middleware_returns_distinct_admin_code_for_api(): void
    {
        $admin = $this->createPlatformAdmin();
        $shop = $this->createShop('retailer');
        $this->adminLock($shop, $admin);
        $owner = $this->ownerFor($shop);

        $response = $this->runAccountMiddleware($owner, Request::create('/api/mobile/v1/dashboard', 'GET'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(SubscriptionRecovery::CODE_SHOP_SUSPENDED, $response->getData(true)['code']);
    }

    // ── 6. isShopOwner ownership validation ────────────────────────────────

    public function test_isshopowner_rejects_a_cross_shop_owner_role(): void
    {
        $shopA = $this->createShop('retailer');
        $shopB = $this->createShop('retailer');
        $ownerRoleB = $this->createOwnerRole($shopB->id); // real 'owner' role of shop B

        // The illegal combination (shop A user pointing at shop B's role) can never
        // be PERSISTED — the DB composite FK users(role_id, shop_id) → roles rejects
        // it. isShopOwner() is the second layer: even on an in-memory/corrupted row
        // it must refuse because role.shop_id != user.shop_id.
        $user = new User();
        $user->shop_id = $shopA->id;
        $user->role_id = $ownerRoleB->id;

        $this->assertFalse(
            $user->isShopOwner(),
            'an owner role from another shop must never grant owner recovery'
        );

        // Sanity: the same owner role IS valid for its own shop.
        $user->shop_id = $shopB->id;
        $this->assertTrue($user->isShopOwner(), 'the owner role is valid for its own shop');
    }

    public function test_db_rejects_persisting_a_cross_shop_role_assignment(): void
    {
        $shopA = $this->createShop('retailer');
        $shopB = $this->createShop('retailer');
        $ownerRoleB = $this->createOwnerRole($shopB->id);

        // The primary defence: the schema forbids the write entirely.
        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create([
            'shop_id' => $shopA->id,
            'role_id' => $ownerRoleB->id,
            'is_active' => true,
        ]);
    }
}
