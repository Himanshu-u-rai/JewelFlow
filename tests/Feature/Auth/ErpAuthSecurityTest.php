<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * ERP Auth — integration / security / session (Module 1). Closes the gaps the
 * existing suites (AuthenticationTest, RealmAuthFrontDoorTest, WebSessionSeatTest)
 * left open: inactive-account blocking (at login AND mid-session), logged-out
 * protected-page redirect, login session regeneration, login throttle, and the
 * platform-admin / shop-auth guard separation.
 *
 * ERP host is jewelflows.com (realm derived from host); tests drive it via
 * absolute URLs, matching RealmAuthFrontDoorTest.
 */
class ErpAuthSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        parent::setUp();
        // Match the established web-auth test pattern: the real stack needs CSRF
        // off (else 419) and the route-level ThrottleRequests off (its array
        // cache accumulates across the suite). The LoginRequest RateLimiter
        // throttle is exercised separately at unit level (AuthUnitTest).
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
        RateLimiter::clear('login-ip:127.0.0.1');
    }

    /** An active ERP owner with a known password, on an active shop. */
    private function erpOwner(string $mobile, bool $active = true): User
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        TenantContext::runFor($shop->id, function () use ($owner, $mobile, $active) {
            $owner->forceFill([
                'mobile_number' => $mobile,
                'password' => Hash::make('password'),
                'realm' => 'erp',
                'is_active' => $active,
            ])->save();
        });

        return $owner->fresh();
    }

    private function login(string $mobile, string $password = 'password')
    {
        return $this->post(self::ERP . '/login', [
            'mobile_number' => $mobile,
            'password' => $password,
        ]);
    }

    // ── Inactive account ───────────────────────────────────────────────────

    public function test_inactive_user_cannot_log_in_and_is_not_authenticated(): void
    {
        $this->erpOwner('9311100001', active: false);

        $res = $this->login('9311100001');

        $res->assertRedirect('/login');
        $res->assertSessionHasErrors('mobile_number');
        $res->assertSessionHas('login_modal', 'account_inactive');
        $this->assertGuest(); // clear denial, not a silent half-login
    }

    public function test_active_user_can_log_in(): void
    {
        $user = $this->erpOwner('9311100002');

        $res = $this->login('9311100002');

        $this->assertAuthenticatedAs($user->fresh());
        $res->assertRedirect(); // owner with shop → app (not /login)
        $this->assertFalse(str_contains($res->headers->get('Location'), '/login'));
    }

    public function test_user_deactivated_mid_session_is_blocked_on_next_request(): void
    {
        $user = $this->erpOwner('9311100003', active: false);

        // Drive EnsureAccountIsActive directly with an authenticated-but-inactive
        // user: it must deny (redirect to /login) and log the user out. (Driving
        // it through the full HTTP stack is unreliable because the in-test
        // SessionGuard caches the user instance across requests; the middleware
        // contract itself is what matters and is asserted here.)
        \Illuminate\Support\Facades\Auth::guard('web')->login($user);
        $this->assertTrue(\Illuminate\Support\Facades\Auth::guard('web')->check());

        $request = \Illuminate\Http\Request::create(self::ERP . '/dashboard', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setRouteResolver(fn () => tap(
            new \Illuminate\Routing\Route(['GET'], '/dashboard', []),
            fn ($r) => $r->name('dashboard')
        ));

        $response = (new \App\Http\Middleware\EnsureAccountIsActive())
            ->handle($request, fn () => response('should not reach', 200));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
        $this->assertFalse(\Illuminate\Support\Facades\Auth::guard('web')->check(), 'middleware must log the inactive user out');
    }

    // ── Logged-out protected access ────────────────────────────────────────

    public function test_guest_cannot_reach_protected_dashboard(): void
    {
        $res = $this->get(self::ERP . '/dashboard');
        $res->assertRedirect(); // bounced to login
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
        $this->assertGuest();
    }

    // ── Session regeneration (fixation protection) ─────────────────────────

    public function test_login_regenerates_the_session_id(): void
    {
        $this->erpOwner('9311100004');

        $this->startSession();
        $before = session()->getId();

        $this->login('9311100004');

        $this->assertNotSame($before, session()->getId(), 'session id must rotate on login');
    }

    // ── Wrong credentials ──────────────────────────────────────────────────

    public function test_wrong_password_is_rejected_with_error(): void
    {
        $this->erpOwner('9311100005');

        $res = $this->login('9311100005', 'not-the-password');

        $res->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_unregistered_mobile_is_rejected(): void
    {
        $res = $this->login('9300999999');

        $res->assertSessionHasErrors('mobile_number');
        $this->assertGuest();
    }

    // (Login throttle / brute-force lockout is unit-tested in AuthUnitTest —
    //  the route-level ThrottleRequests middleware is disabled here to match the
    //  established web-auth test pattern, so it can't be asserted at this level.)

    // ── Platform-admin guard separation ────────────────────────────────────

    public function test_shop_user_is_not_authenticated_on_platform_admin_guard(): void
    {
        $user = $this->erpOwner('9311100007');
        $this->actingAs($user); // default web guard

        // The shop login must NOT grant the platform_admin guard.
        $this->assertFalse(auth('platform_admin')->check());
        $this->assertTrue(auth('web')->check());
    }

    public function test_platform_admin_is_not_authenticated_on_web_guard(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->actingAs($admin, 'platform_admin');

        $this->assertTrue(auth('platform_admin')->check());
        $this->assertFalse(auth('web')->check()); // separate guard, no shop-side auth
    }

    // ── Logout ─────────────────────────────────────────────────────────────

    public function test_logout_clears_authentication(): void
    {
        $user = $this->erpOwner('9311100008');
        $this->actingAs($user);

        $this->post(self::ERP . '/logout');

        $this->assertGuest();
    }
}
