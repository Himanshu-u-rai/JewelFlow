<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function showLogin(): View
    {
        $hasSuperAdmin = PlatformAdmin::where('role', 'super_admin')->exists();
        return view('super-admin.auth.login', compact('hasSuperAdmin'));
    }

    public function showRegister(): View
    {
        $hasSuperAdmin = PlatformAdmin::where('role', 'super_admin')->exists();
        if ($hasSuperAdmin) {
            return redirect()->route('admin.login')
                ->withErrors(['mobile_number' => 'Super admin is already configured.']);
        }

        return view('super-admin.auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile_number' => ['required', 'string', 'digits:10', 'unique:platform_admins,mobile_number'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $admin = \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
                // Lock the platform_admins table row to prevent race condition
                $exists = PlatformAdmin::where('role', 'super_admin')
                    ->lockForUpdate()
                    ->exists();

                if ($exists) {
                    return null;
                }

                return PlatformAdmin::create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
                    'email' => $validated['email'] ?? null,
                    'mobile_number' => $validated['mobile_number'],
                    'password' => Hash::make($validated['password']),
                    'role' => 'super_admin',
                    'is_active' => $this->dbBool(true),
                    'password_changed_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            return redirect()->route('admin.login')
                ->withErrors(['mobile_number' => 'Registration failed. Please try again.']);
        }

        if (!$admin) {
            return redirect()->route('admin.login')
                ->withErrors(['mobile_number' => 'Super admin is already configured.']);
        }

        Auth::guard('platform_admin')->login($admin);
        $request->session()->regenerate();
        $this->audit->log($admin, 'platform_admin.created', PlatformAdmin::class, $admin->id, null, [
            'role' => $admin->role,
            'mobile_number' => $admin->mobile_number,
        ], 'Initial platform super admin bootstrap', $request);

        return redirect()->route('admin.dashboard');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'mobile_number' => ['required', 'string', 'digits:10'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = $this->throttleKey($credentials['mobile_number'], $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $this->audit->logFailedAdminLogin($credentials['mobile_number'], 'login_rate_limited', $request);

            return back()->withErrors([
                'mobile_number' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ])->onlyInput('mobile_number');
        }

        $attempt = Auth::guard('platform_admin')->attempt([
            'mobile_number' => $credentials['mobile_number'],
            'password' => $credentials['password'],
        ], $request->boolean('remember'));

        if (!$attempt) {
            RateLimiter::hit($throttleKey);
            $this->audit->logFailedAdminLogin($credentials['mobile_number'], 'invalid_credentials_or_inactive', $request);
            return back()->withErrors([
                'mobile_number' => 'Invalid super admin credentials.',
            ])->onlyInput('mobile_number');
        }

        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform_admin')->user();
        if (!$admin || !$admin->is_active) {
            Auth::guard('platform_admin')->logout();
            $request->session()->regenerate();
            $request->session()->regenerateToken();
            RateLimiter::hit($throttleKey);
            $this->audit->logFailedAdminLogin($credentials['mobile_number'], 'inactive_account', $request);
            return back()->withErrors([
                'mobile_number' => 'Your platform admin account is inactive.',
            ])->onlyInput('mobile_number');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $admin->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('platform_admin')->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function throttleKey(string $mobileNumber, ?string $ip): string
    {
        return Str::lower($mobileNumber . '|' . ($ip ?: 'unknown'));
    }

    private function dbBool(bool $value)
    {
        return $value;
    }
}
