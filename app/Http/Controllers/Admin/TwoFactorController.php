<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function __construct(
        private TotpService $totp,
        private PlatformAuditService $audit,
    ) {
    }

    /** Show 2FA enrollment page for the currently-logged-in admin. */
    public function showEnroll(): View
    {
        /** @var PlatformAdmin $admin */
        $admin = auth('platform_admin')->user();

        if ($admin->two_factor_enabled) {
            return view('super-admin.auth.2fa-manage', ['admin' => $admin]);
        }

        // Generate a new temporary secret for enrollment (only committed after verification)
        $secret  = $this->totp->generateSecret();
        $uri     = $this->totp->otpauthUri($secret, $admin->mobile_number);
        session()->put('2fa_enroll_secret', $secret);

        return view('super-admin.auth.2fa-enroll', compact('secret', 'uri', 'admin'));
    }

    /** Verify the OTP entered during enrollment and activate 2FA. */
    public function confirmEnroll(Request $request): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin  = auth('platform_admin')->user();
        $secret = session('2fa_enroll_secret');

        if (!$secret) {
            return redirect()->route('admin.2fa.enroll')->withErrors(['otp' => 'Session expired. Please start over.']);
        }

        $otp = preg_replace('/\D/', '', (string) $request->input('otp', ''));
        if (!$this->totp->verify($secret, $otp)) {
            return back()->withErrors(['otp' => 'Invalid code. Check your authenticator app and try again.']);
        }

        $admin->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret'  => $secret,
        ])->save();

        session()->forget('2fa_enroll_secret');

        $this->audit->log(
            $admin,
            'platform_admin.2fa_enabled',
            PlatformAdmin::class,
            $admin->id,
            null,
            ['admin_id' => $admin->id],
            null,
            $request
        );

        return redirect()->route('admin.2fa.enroll')
            ->with('success', 'Two-factor authentication has been enabled on your account.');
    }

    /** Disable 2FA — requires password confirmation. */
    public function disable(Request $request): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = auth('platform_admin')->user();

        $request->validate(['password' => ['required', 'string']]);

        if (!Hash::check($request->input('password'), $admin->password)) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $admin->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret'  => null,
        ])->save();

        $this->audit->log(
            $admin,
            'platform_admin.2fa_disabled',
            PlatformAdmin::class,
            $admin->id,
            null,
            ['admin_id' => $admin->id],
            'Admin disabled 2FA',
            $request
        );

        return redirect()->route('admin.2fa.enroll')
            ->with('success', 'Two-factor authentication has been disabled.');
    }
}
