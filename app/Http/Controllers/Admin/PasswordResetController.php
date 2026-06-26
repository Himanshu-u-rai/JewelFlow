<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Self-service platform-admin password recovery.
 *
 * Security posture:
 *  - Generic responses only — never reveal whether an email exists, is
 *    verified, or is active (no account enumeration).
 *  - A reset link is sent ONLY to an active admin with a verified email; every
 *    other case returns the same generic message and sends nothing.
 *  - Tokens are stored hashed with a 30-minute expiry by the broker.
 *  - Forgot + reset are rate-limited by email+IP.
 *  - A successful reset bumps password_changed_at, which the
 *    EnsurePlatformAdminPasswordFresh middleware uses to log out every other
 *    existing session.
 *  - Nothing logs the email body, token, or password.
 */
class PasswordResetController extends Controller
{
    private const GENERIC = 'If that email belongs to a verified admin account, a password reset link is on its way.';

    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function showLinkRequest(): View
    {
        return view('super-admin.auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $email = Str::lower($validated['email']);

        $key = 'admin-forgot|' . $email . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->withErrors(['email' => "Too many requests. Try again in {$seconds} seconds."]);
        }
        RateLimiter::hit($key, 900);

        $admin = PlatformAdmin::where('email', $email)->first();

        // Only an active, email-verified admin actually receives a link. Every
        // other branch falls through to the identical generic response.
        if ($admin && $admin->is_active && $admin->hasVerifiedEmail()) {
            PasswordBroker::broker('platform_admins')->sendResetLink(['email' => $email]);
            $this->audit->log($admin, 'platform_admin.password_reset_requested', PlatformAdmin::class, $admin->id, null, null, null, $request);
        }

        return back()->with('status', self::GENERIC);
    }

    public function showReset(Request $request, string $token): View
    {
        return view('super-admin.auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $key = 'admin-reset|' . Str::lower($request->input('email')) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->withErrors(['email' => "Too many attempts. Try again in {$seconds} seconds."]);
        }
        RateLimiter::hit($key, 900);

        $status = PasswordBroker::broker('platform_admins')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (PlatformAdmin $admin, string $password) use ($request) {
                // forceFill: password_changed_at bump is what revokes other sessions.
                $admin->forceFill([
                    'password' => Hash::make($password),
                    'password_changed_at' => now(),
                    'remember_token' => Str::random(60),
                ])->save();

                $this->audit->log($admin, 'platform_admin.password_reset', PlatformAdmin::class, $admin->id, null, null, 'Self-service reset via verified email', $request);
            }
        );

        if ($status === PasswordBroker::PASSWORD_RESET) {
            RateLimiter::clear($key);
            return redirect()->route('admin.login')
                ->with('status', 'Password updated. All other sessions were signed out — please log in.');
        }

        // Generic failure (invalid/expired token, unknown email) — no enumeration.
        return back()->withErrors(['email' => 'This reset link is invalid or has expired. Please request a new one.']);
    }
}
