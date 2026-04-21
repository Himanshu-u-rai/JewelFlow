<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsurePlatformRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $admin = Auth::guard('platform_admin')->user();

        if (!$admin) {
            return redirect()->route('admin.login');
        }

        if (!in_array($admin->role, $roles, true)) {
            abort(403, 'Platform role is not authorized for this action.');
        }

        return $next($request);
    }
}
