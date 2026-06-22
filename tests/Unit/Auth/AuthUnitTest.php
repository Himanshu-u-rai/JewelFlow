<?php

namespace Tests\Unit\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\Realm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ERP Auth — unit level (Module 1). Pure logic: realm resolution and login input
 * rules. No HTTP, no session.
 *
 * NOTE: App\Services\OtpService is intentionally NOT tested here — it is dead
 * code (referenced nowhere in the app) and its `otps` table is a stub (only
 * id + timestamps; the create_otps_table migration never added the mobile/code
 * columns it reads), so it would throw if ever called. Flagged in the module
 * report; the live ERP auth path is password-based, and email verification uses
 * EmailVerificationOtpController (its own hashed-OTP flow), not OtpService.
 */
class AuthUnitTest extends TestCase
{
    use RefreshDatabase;

    // ── Realm helper ───────────────────────────────────────────────────────

    public function test_realm_from_host_defaults_to_erp(): void
    {
        $this->assertSame(Realm::ERP, Realm::fromHost('jewelflows.com'));
        $this->assertSame(Realm::ERP, Realm::fromHost('www.jewelflows.com'));
        $this->assertSame(Realm::ERP, Realm::fromHost(null));
    }

    public function test_realm_from_host_detects_dhiran_subdomain(): void
    {
        $this->assertSame(Realm::DHIRAN, Realm::fromHost('dhiran.jewelflows.com'));
    }

    public function test_realm_is_valid(): void
    {
        $this->assertTrue(Realm::isValid('erp'));
        $this->assertTrue(Realm::isValid('dhiran'));
        $this->assertFalse(Realm::isValid('admin'));
        $this->assertFalse(Realm::isValid(null));
        $this->assertFalse(Realm::isValid(''));
    }

    public function test_realm_constants_match_user(): void
    {
        $this->assertSame(User::REALM_ERP, Realm::ERP);
        $this->assertSame(User::REALM_DHIRAN, Realm::DHIRAN);
    }

    // ── Login input rules ──────────────────────────────────────────────────

    public function test_login_requires_ten_digit_mobile_and_password(): void
    {
        $rules = (new LoginRequest())->rules();

        // Valid input passes.
        $this->assertFalse(Validator::make(
            ['mobile_number' => '9876543210', 'password' => 'secret'], $rules
        )->fails());

        // Too short / non-digit / missing all fail.
        foreach ([
            ['mobile_number' => '12345', 'password' => 'x'],         // <10 digits
            ['mobile_number' => 'abcdefghij', 'password' => 'x'],    // non-digit
            ['mobile_number' => '98765432101', 'password' => 'x'],   // >10 digits
            ['mobile_number' => '9876543210'],                       // no password
            ['password' => 'x'],                                     // no mobile
        ] as $bad) {
            $this->assertTrue(Validator::make($bad, $rules)->fails());
        }
    }

    // ── Login throttle (brute-force lockout) ───────────────────────────────

    public function test_login_throttles_after_five_failed_attempts(): void
    {
        $request = LoginRequest::create('/login', 'POST', ['mobile_number' => '9876500099']);
        $request->setLaravelSession(app('session.store'));
        $request->server->set('REMOTE_ADDR', '203.0.113.9');

        // Under the per-(mobile|ip) limit of 5 → no throttle.
        for ($i = 0; $i < 4; $i++) {
            RateLimiter::hit($request->throttleKey());
        }
        $request->ensureIsNotRateLimited(); // 4 hits → still allowed (no throw)
        $this->assertTrue(true);

        // Reach the limit → the next check throws + flags the throttled modal.
        RateLimiter::hit($request->throttleKey());

        try {
            $request->ensureIsNotRateLimited();
            $this->fail('expected ValidationException once the rate limit is hit');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('mobile_number', $e->errors());
            $this->assertSame('throttled', session('login_modal'));
        }

        RateLimiter::clear($request->throttleKey());
    }
}
