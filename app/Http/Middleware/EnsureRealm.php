<?php

namespace App\Http\Middleware;

use App\Support\Realm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Realm gate for authenticated routes, applied as `realm:erp`. A logged-in user
 * may only reach routes for their OWN realm, even via a crafted URL.
 *
 * Guests are left to the route's `auth` middleware (this only enforces realm for
 * an authenticated user). A realm mismatch is refused.
 */
class EnsureRealm
{
    public function handle(Request $request, Closure $next, string $realm): Response
    {
        $user = $request->user();

        // No authenticated user → let the route's own auth middleware decide.
        if (! $user) {
            return $next($request);
        }

        $userRealm = Realm::of($user);

        if ($userRealm === $realm) {
            return $next($request);
        }

        abort(403);
    }
}
