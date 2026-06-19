<?php

namespace App\Http\Middleware;

use App\Support\Realm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Realm gate for authenticated routes (Dhiran separation, Phase 2).
 *
 * Applied as `realm:dhiran` or `realm:erp`. A logged-in user may only reach
 * routes for their OWN realm — an ERP account can never hit a Dhiran app route
 * and vice-versa, even via a crafted URL. Belt-and-suspenders on top of the
 * per-host session cookie isolation (a Dhiran session and an ERP session are
 * already separate cookies).
 *
 * Guests are left to the route's `auth` middleware (this only enforces realm for
 * an authenticated user). On a realm mismatch we send the user to their own
 * realm's home rather than expose the other product.
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

        // Cross-realm access: route the user back to their own realm's home.
        return $userRealm === Realm::DHIRAN
            ? redirect()->route('dhiran.dashboard')
            : redirect()->route('dashboard');
    }
}
