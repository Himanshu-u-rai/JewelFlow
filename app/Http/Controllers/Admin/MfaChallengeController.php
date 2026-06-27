<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Http\Middleware\EnsurePlatformAdminPasswordFresh;
use App\Mail\EmailOtpMail;
use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Phase 2 second factor. The admin is already authenticated by password but is
 * MFA-gated (EnsurePlatformAdminMfa). They clear the challenge with:
 *   - a TOTP code if they have an authenticator enrolled, otherwise
 *   - an emailed 6-digit OTP (hashed in cache, 10-min TTL), or
 *   - a single-use recovery code.
 *
 * The OTP/recovery code is never logged. The challenge itself expires after
 * EnsurePlatformAdminMfa::CHALLENGE_TTL_MINUTES — past that the admin is logged
 * out and must re-enter their password.
 */
class MfaChallengeController extends Controller
{
    private const OTP_TTL_MINUTES = 10;

    public function __construct(
        private PlatformAuditService $audit,
        private TotpService $totp,
    ) {
    }

    private function admin(): PlatformAdmin
    {
        return Auth::guard('platform_admin')->user();
    }

    private function usesTotp(PlatformAdmin $admin): bool
    {
        return $admin->two_factor_enabled && $admin->two_factor_secret;
    }

    private function otpKey(PlatformAdmin $admin): string
    {
        return 'admin-mfa-otp:' . $admin->id;
    }

    /** True (and logs the admin out) when the password step is too old to trust. */
    private function expired(Request $request): bool
    {
        $started = (int) $request->session()->get(EnsurePlatformAdminMfa::SESSION_STARTED, 0);

        return $started > 0
            && (now()->getTimestamp() - $started) > EnsurePlatformAdminMfa::CHALLENGE_TTL_MINUTES * 60;
    }

    private function abortExpired(Request $request): RedirectResponse
    {
        Auth::guard('platform_admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->withErrors(['mobile_number' => 'Your login challenge expired. Please sign in again.']);
    }

    private function sendEmailOtp(PlatformAdmin $admin, Request $request): void
    {
        $otp = (string) random_int(100000, 999999);
        Cache::put($this->otpKey($admin), Hash::make($otp), now()->addMinutes(self::OTP_TTL_MINUTES));
        Mail::to($admin->email)->send(new EmailOtpMail($otp, 'JewelFlow Platform'));
        $this->audit->log($admin, 'platform_admin.mfa_otp_sent', PlatformAdmin::class, $admin->id, null, null, null, $request);
    }

    public function show(Request $request): RedirectResponse|View
    {
        $admin = $this->admin();

        if ($request->session()->get(EnsurePlatformAdminMfa::SESSION_PASSED) === true) {
            return redirect()->route('admin.dashboard');
        }
        if ($this->expired($request)) {
            return $this->abortExpired($request);
        }

        $method = $this->usesTotp($admin) ? 'totp' : 'email';

        // Email method: send the first code automatically (rate-limited resend after).
        if ($method === 'email' && ! Cache::has($this->otpKey($admin))) {
            $key = 'admin-mfa-send|' . $admin->id;
            if (! RateLimiter::tooManyAttempts($key, 3)) {
                RateLimiter::hit($key, 3600);
                $this->sendEmailOtp($admin, $request);
            }
        }

        return view('super-admin.auth.mfa', [
            'method' => $method,
            'email' => $admin->email,
            'hasRecoveryCodes' => $admin->hasRecoveryCodes(),
        ]);
    }

    public function resend(Request $request): RedirectResponse
    {
        $admin = $this->admin();
        if ($this->expired($request)) {
            return $this->abortExpired($request);
        }
        if ($this->usesTotp($admin)) {
            return back(); // TOTP has nothing to resend.
        }

        $key = 'admin-mfa-send|' . $admin->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors(['otp' => 'Too many code requests. Try again later.']);
        }
        RateLimiter::hit($key, 3600);
        $this->sendEmailOtp($admin, $request);

        return back()->with('status', 'A new code has been sent to your email.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $admin = $this->admin();

        if ($request->session()->get(EnsurePlatformAdminMfa::SESSION_PASSED) === true) {
            return redirect()->route('admin.dashboard');
        }
        if ($this->expired($request)) {
            return $this->abortExpired($request);
        }

        $key = 'admin-mfa-verify|' . $admin->id . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->withErrors(['otp' => "Too many attempts. Try again in {$seconds} seconds."]);
        }

        $recoveryCode = trim((string) $request->input('recovery_code', ''));
        $otp = preg_replace('/\D/', '', (string) $request->input('otp', ''));

        if ($recoveryCode !== '') {
            if (! $admin->useRecoveryCode($recoveryCode)) {
                RateLimiter::hit($key, 900);
                $this->audit->log($admin, 'platform_admin.mfa_failed', PlatformAdmin::class, $admin->id, null, ['reason' => 'recovery_code'], null, $request);
                return back()->withErrors(['otp' => 'That recovery code is invalid.']);
            }
            $this->audit->log($admin, 'platform_admin.recovery_code_used', PlatformAdmin::class, $admin->id, null, null, null, $request);
            return $this->pass($request, $admin);
        }

        $ok = $this->usesTotp($admin)
            ? $this->totp->verify((string) $admin->two_factor_secret, $otp)
            : ($otp !== '' && ($h = Cache::get($this->otpKey($admin))) && Hash::check($otp, $h));

        if (! $ok) {
            RateLimiter::hit($key, 900);
            $this->audit->log($admin, 'platform_admin.mfa_failed', PlatformAdmin::class, $admin->id, null, ['reason' => 'otp'], null, $request);
            return back()->withErrors(['otp' => 'That code is invalid or has expired.']);
        }

        Cache::forget($this->otpKey($admin));
        $this->audit->log($admin, 'platform_admin.mfa_success', PlatformAdmin::class, $admin->id, null, null, null, $request);

        return $this->pass($request, $admin);
    }

    /** Mark MFA cleared, harden the session, and route onward. */
    private function pass(Request $request, PlatformAdmin $admin): RedirectResponse
    {
        RateLimiter::clear('admin-mfa-verify|' . $admin->id . '|' . $request->ip());
        $request->session()->regenerate();
        $request->session()->put(EnsurePlatformAdminMfa::SESSION_PASSED, true);
        $request->session()->put(EnsurePlatformAdminPasswordFresh::SESSION_KEY, $admin->password_changed_at?->getTimestamp() ?? 0);
        $request->session()->forget(EnsurePlatformAdminMfa::SESSION_STARTED);
        $admin->forceFill(['last_login_at' => now()])->save();

        // First login with no recovery codes → force the admin to capture a set.
        if (! $admin->hasRecoveryCodes()) {
            return redirect()->route('admin.recovery-codes.show')
                ->with('status', 'Save your recovery codes before continuing.');
        }

        return redirect()->intended(route('admin.dashboard'));
    }
}
