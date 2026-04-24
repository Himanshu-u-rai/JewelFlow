<?php

namespace App\Http\Middleware;

use Closure;

class RoleMiddleware
{
    public function handle($request, Closure $next, ...$roles)
    {
        // Check if user is authenticated
        if (!auth()->check()) {
            abort(403, 'Unauthorized');
        }

        $user = auth()->user();

        // If user has no shop yet, redirect to shop creation
        if (is_null($user->shop_id)) {
            return redirect()->route('shops.create');
        }

        // If user has no role assigned, they shouldn't access role-protected routes
        if (is_null($user->role_id) || is_null($user->role)) {
            abort(403, 'Unauthorized - Your account has not been assigned a role.');
        }

        $userRoleName = $user->role->name;

        // Check if user's role matches any of the allowed roles
        if (!in_array($userRoleName, $roles)) {
            abort(403, 'Unauthorized - You do not have permission to access this page.');
        }

        return $next($request);
    }
}
