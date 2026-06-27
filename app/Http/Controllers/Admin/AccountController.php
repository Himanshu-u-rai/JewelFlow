<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePlatformAdminPasswordFresh;
use App\Mail\EmailOtpMail;
use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Self-service platform-admin contact changes. Email (recovery channel) and
 * mobile (login id) are never edited in place: the new value is staged in
 * pending_* and only becomes primary after an OTP proves control. Every change
 * needs the current password (re-auth) on top of the MFA-passed session that
 * the route group already requires, bumps the session-trust stamp to log out
 * other sessions, and is audited. OTPs are hashed in the cache and never logged.
 */
class AccountController extends Controller
{
    private const OTP_TTL_MINUTES = 10;

    public function __construct(private PlatformAuditService $audit)
    {
    }

    private function admin(): PlatformAdmin
    {
        return Auth::guard('platform_admin')->user();
    }

    public function show(): View
    {
        return view('super-admin.account.index', ['admin' => $this->admin()]);
    }

    /** Re-stamp the current session and bump password_changed_at so EVERY other
     *  session is logged out by EnsurePlatformAdminPasswordFresh on its next hit. */
    private function revokeOtherSessions(Request $request, PlatformAdmin $admin): void
    {
        $admin->forceFill(['password_changed_at' => now()])->save();
        $request->session()->put(EnsurePlatformAdminPasswordFresh::SESSION_KEY, $admin->password_changed_at->getTimestamp());
    }

    private function requireCurrentPassword(Request $request, PlatformAdmin $admin): bool
    {
        return Hash::check((string) $request->input('current_password'), $admin->password);
    }

    // ── Email change ──────────────────────────────────────────────────────────

    public function requestEmailChange(Request $request): RedirectResponse
    {
        $admin = $this->admin();
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_email' => ['required', 'email', 'max:255', Rule::unique('platform_admins', 'email')->ignore($admin->id)],
        ]);

        if (! $this->requireCurrentPassword($request, $admin)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }
        if (strcasecmp($validated['new_email'], (string) $admin->email) === 0) {
            return back()->withErrors(['new_email' => 'That is already your email.']);
        }

        $key = 'admin-email-change|' . $admin->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['new_email' => 'Too many requests. Try again later.']);
        }
        RateLimiter::hit($key, 3600);

        $admin->forceFill(['pending_email' => $validated['new_email']])->save();
        $otp = (string) random_int(100000, 999999);
        Cache::put('admin-email-change-otp:' . $admin->id, Hash::make($otp), now()->addMinutes(self::OTP_TTL_MINUTES));
        Mail::to($validated['new_email'])->send(new EmailOtpMail($otp, 'JewelFlow Platform'));

        $this->audit->log($admin, 'platform_admin.email_change_requested', PlatformAdmin::class, $admin->id, null, null, null, $request);

        return back()->with('status', "We sent a 6-digit code to {$validated['new_email']}. Enter it below to confirm.");
    }

    public function verifyEmailChange(Request $request): RedirectResponse
    {
        $admin = $this->admin();
        $request->validate(['otp' => ['required', 'string', 'digits:6']]);

        if (! $admin->pending_email) {
            return back()->withErrors(['otp' => 'No pending email change.']);
        }

        $key = 'admin-email-change-verify|' . $admin->id;
        if (RateLimiter::tooManyAttempts($key, 6)) {
            return back()->withErrors(['otp' => 'Too many attempts. Try again later.']);
        }

        $hash = Cache::get('admin-email-change-otp:' . $admin->id);
        if (! $hash || ! Hash::check($request->input('otp'), $hash)) {
            RateLimiter::hit($key, 900);
            $this->audit->log($admin, 'platform_admin.email_change_failed', PlatformAdmin::class, $admin->id, null, null, null, $request);
            return back()->withErrors(['otp' => 'That code is invalid or has expired.']);
        }

        $oldEmail = $admin->email;
        $newEmail = $admin->pending_email;
        $admin->forceFill([
            'email' => $newEmail,
            'email_verified_at' => now(),
            'pending_email' => null,
        ])->save();

        Cache::forget('admin-email-change-otp:' . $admin->id);
        RateLimiter::clear($key);
        $this->revokeOtherSessions($request, $admin);
        $this->audit->log($admin, 'platform_admin.email_changed', PlatformAdmin::class, $admin->id, ['email' => $oldEmail], ['email' => $newEmail], null, $request);
        $this->notifyChange($oldEmail, $newEmail, 'email', $newEmail);

        return back()->with('status', 'Your email has been updated. Other sessions were signed out.');
    }

    // ── Mobile change ─────────────────────────────────────────────────────────

    public function requestMobileChange(Request $request): RedirectResponse
    {
        $admin = $this->admin();
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_mobile' => ['required', 'string', 'digits:10', Rule::unique('platform_admins', 'mobile_number')->ignore($admin->id)],
        ]);

        if (! $this->requireCurrentPassword($request, $admin)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }
        // The OTP goes to the verified email, so an unverified admin can't change mobile here.
        if (! $admin->hasVerifiedEmail()) {
            return back()->withErrors(['new_mobile' => 'Verify your email before changing your mobile number.']);
        }
        if ($validated['new_mobile'] === (string) $admin->mobile_number) {
            return back()->withErrors(['new_mobile' => 'That is already your mobile number.']);
        }

        $key = 'admin-mobile-change|' . $admin->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['new_mobile' => 'Too many requests. Try again later.']);
        }
        RateLimiter::hit($key, 3600);

        $admin->forceFill(['pending_mobile' => $validated['new_mobile']])->save();
        $otp = (string) random_int(100000, 999999);
        Cache::put('admin-mobile-change-otp:' . $admin->id, Hash::make($otp), now()->addMinutes(self::OTP_TTL_MINUTES));
        Mail::to($admin->email)->send(new EmailOtpMail($otp, 'JewelFlow Platform'));

        $this->audit->log($admin, 'platform_admin.mobile_change_requested', PlatformAdmin::class, $admin->id, null, null, null, $request);

        return back()->with('status', "We sent a 6-digit code to {$admin->email}. Enter it below to confirm the new mobile number.");
    }

    public function verifyMobileChange(Request $request): RedirectResponse
    {
        $admin = $this->admin();
        $request->validate(['otp' => ['required', 'string', 'digits:6']]);

        if (! $admin->pending_mobile) {
            return back()->withErrors(['otp' => 'No pending mobile change.']);
        }

        $key = 'admin-mobile-change-verify|' . $admin->id;
        if (RateLimiter::tooManyAttempts($key, 6)) {
            return back()->withErrors(['otp' => 'Too many attempts. Try again later.']);
        }

        $hash = Cache::get('admin-mobile-change-otp:' . $admin->id);
        if (! $hash || ! Hash::check($request->input('otp'), $hash)) {
            RateLimiter::hit($key, 900);
            $this->audit->log($admin, 'platform_admin.mobile_change_failed', PlatformAdmin::class, $admin->id, null, null, null, $request);
            return back()->withErrors(['otp' => 'That code is invalid or has expired.']);
        }

        $old = $admin->mobile_number;
        $new = $admin->pending_mobile;
        $admin->forceFill(['mobile_number' => $new, 'pending_mobile' => null])->save();

        Cache::forget('admin-mobile-change-otp:' . $admin->id);
        RateLimiter::clear($key);
        $this->revokeOtherSessions($request, $admin);
        $this->audit->log($admin, 'platform_admin.mobile_changed', PlatformAdmin::class, $admin->id, ['mobile_number' => $old], ['mobile_number' => $new], null, $request);
        $this->notifyChange($admin->email, $admin->email, 'mobile number', $new);

        return back()->with('status', 'Your mobile number has been updated. Other sessions were signed out.');
    }

    /** Alert the old address + confirm the new one. Plain text — no secrets. */
    private function notifyChange(?string $oldEmail, ?string $confirmEmail, string $what, string $newValue): void
    {
        if ($oldEmail) {
            Mail::raw("Your JewelFlow platform admin {$what} was just changed. If this wasn't you, contact support immediately.", fn ($m) => $m->to($oldEmail)->subject("JewelFlow security alert — {$what} changed"));
        }
        if ($confirmEmail && $confirmEmail !== $oldEmail) {
            Mail::raw("Your JewelFlow platform admin {$what} is now {$newValue}.", fn ($m) => $m->to($confirmEmail)->subject("JewelFlow — {$what} updated"));
        }
    }
}
