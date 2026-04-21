<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthorized');
        }

        // Owner always has all permissions
        if ($user->isOwner()) {
            return $next($request);
        }

        if (!$user->hasPermission($permission)) {
            abort(403, 'You do not have permission to access this resource.');
        }

        return $next($request);
    }
}
