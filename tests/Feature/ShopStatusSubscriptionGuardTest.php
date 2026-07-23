<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Http\Middleware\EnsureSubscriptionIsActive;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Pins the JF-0001 (Goldlux) "daily deactivation" root cause: the generic
 * Super Admin Activate/Restore toggle (ShopManagementController::updateStatus)
 * could set access_mode=active with zero awareness of the ShopSubscription
 * table, so EnsureSubscriptionIsActive immediately reconciled it straight back
 * to read_only on the very next request — every single time. The toggle must
 * refuse to promise access the subscription can't back.
 */
class ShopStatusSubscriptionGuardTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function verifiedAdmin(): PlatformAdmin
    {
        $admin = $this->createPlatformAdmin();
        $admin->forceFill(['email_verified_at' => now()])->save();

        return $admin;
    }

    private function actingAsAdmin(PlatformAdmin $admin): self
    {
        return $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    private function retailPlan(): Plan
    {
        return Plan::create([
            'code' => 'retailer_guard_' . fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Retailer',
            'price_monthly' => 4999,
            'price_yearly' => 50000,
            'grace_days' => 7,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
        ]);
    }

    private function activate(PlatformAdmin $admin, Shop $shop)
    {
        return $this->actingAsAdmin($admin)
            ->patch(route('admin.shops.status', $shop), ['access_mode' => 'active', 'reason' => 'test']);
    }

    // Reproduces the exact Goldlux row shape: several stale read_only rows,
    // nothing entitling today. The toggle must refuse to activate.
    public function test_activation_blocked_without_a_covering_subscription(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->verifiedAdmin();
        $shop = $this->createShop('retailer');
        $shop->forceFill(['access_mode' => 'read_only', 'is_active' => false])->save();
        $plan = $this->retailPlan();

        foreach ([1, 2, 3] as $_) {
            ShopSubscription::create([
                'shop_id' => $shop->id,
                'plan_id' => $plan->id,
                'status' => 'read_only',
                'starts_at' => now()->subMonth()->toDateString(),
                'ends_at' => now()->subWeek()->toDateString(),
                'grace_ends_at' => now()->subWeek()->toDateString(),
                'billing_cycle' => 'yearly',
                'updated_by_admin_id' => $admin->id,
            ]);
        }

        $response = $this->activate($admin, $shop);

        $response->assertSessionHasErrors('access_mode');
        $this->assertSame('read_only', $shop->fresh()->access_mode,
            'The toggle must not flip access_mode when no subscription entitles access today.');
    }

    // A newer, genuinely valid row makes activation succeed — and older stale
    // rows must never be consulted ahead of it (latest-by-id wins).
    public function test_activation_allowed_when_a_newer_row_entitles_access_today(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->verifiedAdmin();
        $shop = $this->createShop('retailer');
        $shop->forceFill(['access_mode' => 'read_only', 'is_active' => false])->save();
        $plan = $this->retailPlan();

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'read_only',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at' => now()->subWeek()->toDateString(),
            'grace_ends_at' => now()->subWeek()->toDateString(),
            'billing_cycle' => 'yearly',
            'updated_by_admin_id' => $admin->id,
        ]);
        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->addDays(7)->toDateString(),
            'billing_cycle' => 'yearly',
            'price_paid' => 50000,
            'updated_by_admin_id' => $admin->id,
        ]);

        $response = $this->activate($admin, $shop);

        $response->assertSessionDoesntHaveErrors('access_mode');
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // Enforcement disabled (default) must leave the toggle's pre-existing,
    // subscription-agnostic behavior untouched — no regression for shops
    // running without billing enforcement turned on.
    public function test_guard_is_a_noop_when_enforcement_is_disabled(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        $admin = $this->verifiedAdmin();
        $shop = $this->createShop('retailer');
        $shop->forceFill(['access_mode' => 'read_only', 'is_active' => false])->save();

        $response = $this->activate($admin, $shop);

        $response->assertSessionDoesntHaveErrors('access_mode');
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // Suspending/read-only-ing is never gated by subscription entitlement —
    // the guard only ever blocks a promise of ACTIVE access.
    public function test_suspend_is_never_blocked_by_the_subscription_guard(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->verifiedAdmin();
        $shop = $this->createShop('retailer');

        $response = $this->actingAsAdmin($admin)
            ->patch(route('admin.shops.status', $shop), ['access_mode' => 'suspended', 'reason' => 'test']);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame('suspended', $shop->fresh()->access_mode);
    }

    // Running the daily scheduler twice on the same expired row must be a
    // true no-op the second time — no duplicate transition/event.
    public function test_scheduler_run_twice_is_idempotent(): void
    {
        $admin = $this->verifiedAdmin();
        $shop = $this->createShop('retailer');
        $plan = $this->retailPlan();

        $sub = ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subYear()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->subDay()->toDateString(),
            'billing_cycle' => 'yearly',
            'price_paid' => 50000,
            'updated_by_admin_id' => $admin->id,
        ]);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);
        $afterFirstRun = $sub->fresh()->status;
        $eventsAfterFirstRun = \App\Models\Platform\SubscriptionEvent::where('shop_subscription_id', $sub->id)->count();

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame($afterFirstRun, $sub->fresh()->status, 'A second scheduler run must not change an already-reconciled row.');
        $this->assertSame(
            $eventsAfterFirstRun,
            \App\Models\Platform\SubscriptionEvent::where('shop_subscription_id', $sub->id)->count(),
            'A second scheduler run must not write a duplicate transition event.'
        );
    }

    // Administrative suspension must never be silently undone by subscription
    // reconciliation, even when the underlying subscription is genuinely valid.
    public function test_administrative_suspension_survives_middleware_reconciliation(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $admin = $this->verifiedAdmin();
        $shop = $this->createShop('retailer');
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $plan = $this->retailPlan();

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->addDays(7)->toDateString(),
            'billing_cycle' => 'yearly',
            'price_paid' => 50000,
            'updated_by_admin_id' => $admin->id,
        ]);

        // A distinct, deliberate admin suspension — independent of billing.
        $this->actingAsAdmin($admin)
            ->patch(route('admin.shops.status', $shop), ['access_mode' => 'suspended', 'reason' => 'policy violation']);
        $this->assertSame('suspended', $shop->fresh()->access_mode);

        $this->actingAs($user);
        $middleware = app(EnsureSubscriptionIsActive::class);
        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession(app('session.store'));
        $middleware->handle($request, fn ($r) => response('ok'));

        $this->assertSame('suspended', $shop->fresh()->access_mode,
            'A valid subscription must never resurrect an administratively suspended shop.');
    }
}
