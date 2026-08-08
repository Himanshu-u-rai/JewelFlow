<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSubscriptionIsActive;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * SUBSCRIPTION RECOVERY LIFECYCLE — the "Account deactivated" trap fix.
 *
 * When a trial/paid subscription lapses, the nightly scheduler stamps the shop
 * is_active=false + access_mode=suspended + suspension_reason="Subscription …".
 * Two sites used to treat that identically to an ADMIN deactivation and log the
 * owner straight back out to /login, trapping them off the plan picker with no
 * way to pay:
 *   1. AuthenticatedSessionController::store() — logout on login.
 *   2. EnsureSubscriptionIsActive — deny()+logout on the next protected request.
 *
 * The fix classifies the suspension via Shop::suspensionIsSubscriptionManaged()
 * and routes a subscription lapse to RECOVERY (owner → plan picker, no logout;
 * staff → owner-must-renew; API → 402 SUBSCRIPTION_REQUIRED), while a genuine
 * ADMIN suspension keeps its Contact-Support dead end untouched.
 */
class SubscriptionRecoveryLifecycleTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** A shop deactivated by the subscription lifecycle (recoverable). */
    private function lapsedShop(string $reason = 'Subscription expired'): Shop
    {
        $shop = $this->createShop('retailer');
        $shop->forceFill([
            'access_mode' => 'suspended',
            'is_active' => false,
            'suspension_reason' => $reason,
        ])->save();

        return $shop;
    }

    private function ownerFor(Shop $shop, string $password = 'password'): User
    {
        $role = $this->createOwnerRole($shop->id); // name = 'owner'
        $user = $this->createOwnerUser($shop, $role);
        $user->forceFill(['realm' => 'erp', 'password' => Hash::make($password)])->save();

        return $user;
    }

    private function staffFor(Shop $shop, string $password = 'password'): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $shop->id])->save();
        $user = User::factory()->create([
            'shop_id' => $shop->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $user->forceFill(['realm' => 'erp', 'password' => Hash::make($password)])->save();

        return $user;
    }

    // ── Classifier (authoritative source of truth) ─────────────────────────

    public function test_classifier_flags_subscription_reasons_and_rejects_admin_reasons(): void
    {
        $shop = $this->createShop('retailer');

        foreach (['Subscription expired', 'Subscription grace period ended', 'No active subscription found for shop.', 'middleware-check'] as $reason) {
            $shop->suspension_reason = $reason;
            $this->assertTrue($shop->suspensionIsSubscriptionManaged(), "'{$reason}' must be recoverable");
        }

        foreach (['policy violation', 'Suspended by subscription status', 'Fraud investigation', ''] as $reason) {
            $shop->suspension_reason = $reason;
            $this->assertFalse($shop->suspensionIsSubscriptionManaged(), "'{$reason}' must NOT be treated as recoverable");
        }
    }

    // ── Login-time recovery ────────────────────────────────────────────────

    public function test_owner_of_lapsed_shop_logs_in_to_the_plan_picker_not_logged_out(): void
    {
        $shop = $this->lapsedShop();
        $owner = $this->ownerFor($shop);

        $res = $this->post(route('login'), ['mobile_number' => $owner->mobile_number, 'password' => 'password']);

        $res->assertRedirect(route('subscription.plans'));
        $this->assertAuthenticatedAs($owner, 'web');
    }

    public function test_staff_of_lapsed_shop_cannot_reach_plans_and_is_told_owner_must_renew(): void
    {
        $shop = $this->lapsedShop();
        $staff = $this->staffFor($shop);

        $res = $this->post(route('login'), ['mobile_number' => $staff->mobile_number, 'password' => 'password']);

        $res->assertRedirect('/login');
        $res->assertSessionHasErrors('mobile_number');
        $this->assertGuest('web');
    }

    public function test_admin_suspended_owner_still_gets_the_deactivated_dead_end_not_recovery(): void
    {
        $shop = $this->lapsedShop('policy violation'); // admin, NOT subscription-managed
        $owner = $this->ownerFor($shop);

        $res = $this->post(route('login'), ['mobile_number' => $owner->mobile_number, 'password' => 'password']);

        $res->assertRedirect('/login');
        $res->assertSessionHas('login_modal', 'shop_deactivated');
        $this->assertGuest('web');
    }

    // ── Middleware-time recovery (next protected request) ──────────────────

    private function runMiddleware(User $user, Request $request)
    {
        $this->actingAs($user);
        $request->setLaravelSession(app('session.store'));

        return app(EnsureSubscriptionIsActive::class)->handle($request, fn ($r) => response('ok'));
    }

    public function test_middleware_redirects_lapsed_owner_to_plans_without_logout(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $shop = $this->lapsedShop();
        $owner = $this->ownerFor($shop);

        $response = $this->runMiddleware($owner, Request::create('/dashboard', 'GET'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString(route('subscription.plans'), $response->headers->get('Location'));
        $this->assertTrue(Auth::check(), 'owner must NOT be logged out by recovery');
    }

    public function test_middleware_returns_stable_subscription_required_code_for_api(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $shop = $this->lapsedShop();
        $owner = $this->ownerFor($shop);

        $response = $this->runMiddleware($owner, Request::create('/api/mobile/v1/dashboard', 'GET'));

        // 403 keeps the existing "lapsed tenant is forbidden" contract; the
        // stable code is what lets the mobile app branch to a renew flow.
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('SUBSCRIPTION_REQUIRED', $response->getData(true)['code']);
    }

    public function test_middleware_keeps_admin_suspension_as_contact_support(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        $shop = $this->lapsedShop('policy violation'); // admin suspension
        $owner = $this->ownerFor($shop);

        $response = $this->runMiddleware($owner, Request::create('/api/mobile/v1/dashboard', 'GET'));

        // denyAdministrative() path, not recover(): 403 with the DISTINCT
        // administrative code so mobile can show "contact support" (never a
        // renew flow). This must never be SUBSCRIPTION_REQUIRED.
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('SHOP_SUSPENDED', $response->getData(true)['code']);
    }
}
