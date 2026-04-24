<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('platform_admin');

        if (!$guard->check()) {
            return redirect()->route('admin.login');
        }

        $admin = $guard->user();

        if (!$admin->is_active) {
            $guard->logout();
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')
                ->withErrors(['mobile_number' => 'Your platform admin account is inactive.']);
        }

        return $next($request);
    }
}
