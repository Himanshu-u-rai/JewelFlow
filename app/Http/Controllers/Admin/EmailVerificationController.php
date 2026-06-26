<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
use Illuminate\View\View;

/**
 * A logged-in platform admin verifies their own email address so it can later
 * be used for self-service password recovery. The 6-digit OTP is stored hashed
 * in the cache with a 10-minute TTL — never in the DB, never in plaintext, and
 * never logged.
 */
class EmailVerificationController extends Controller
{
    private const OTP_TTL_MINUTES = 10;

    public function __construct(private PlatformAuditService $audit)
    {
    }

    private function admin(): PlatformAdmin
    {
        return Auth::guard('platform_admin')->user();
    }

    private function cacheKey(PlatformAdmin $admin): string
    {
        return 'admin-email-otp:' . $admin->id;
    }

    public function show(): View
    {
        return view('super-admin.auth.verify-email', ['admin' => $this->admin()]);
    }

    public function send(Request $request): RedirectResponse
    {
        $admin = $this->admin();

        if (! $admin->email) {
            return back()->withErrors(['email' => 'No email is set on this admin account.']);
        }
        if ($admin->hasVerifiedEmail()) {
            return back()->with('status', 'Your email is already verified.');
        }

        $key = 'admin-email-send|' . $admin->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->withErrors(['email' => "Too many requests. Try again in {$seconds} seconds."]);
        }
        RateLimiter::hit($key, 3600);

        $otp = (string) random_int(100000, 999999);
        Cache::put($this->cacheKey($admin), Hash::make($otp), now()->addMinutes(self::OTP_TTL_MINUTES));

        Mail::to($admin->email)->send(new EmailOtpMail($otp, 'JewelFlow Platform'));

        return back()->with('status', "We sent a 6-digit code to {$admin->email}. It expires in " . self::OTP_TTL_MINUTES . ' minutes.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['otp' => ['required', 'string', 'digits:6']]);
        $admin = $this->admin();

        $key = 'admin-email-verify|' . $admin->id;
        if (RateLimiter::tooManyAttempts($key, 6)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->withErrors(['otp' => "Too many attempts. Try again in {$seconds} seconds."]);
        }

        $hashed = Cache::get($this->cacheKey($admin));
        if (! $hashed || ! Hash::check($request->input('otp'), $hashed)) {
            RateLimiter::hit($key, 900);
            return back()->withErrors(['otp' => 'That code is invalid or has expired.']);
        }

        RateLimiter::clear($key);
        Cache::forget($this->cacheKey($admin));
        $admin->markEmailAsVerified();
        $this->audit->log($admin, 'platform_admin.email_verified', PlatformAdmin::class, $admin->id, null, null, null, $request);

        return back()->with('status', 'Your email is now verified. You can use it for password recovery.');
    }
}
