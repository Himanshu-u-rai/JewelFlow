<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Manual owner "Close Shop" switch. When the owner closes the shop
 * (shop_access_enabled=false) every non-owner user is blocked from operational
 * routes; the owner always passes through so they can reopen the shop.
 *
 * Default is OPEN: a missing preferences row or null column reads as enabled so
 * existing shops are never blocked after the migration. Closing only flips the
 * flag — no records are deleted and access is restored the moment it reopens.
 *
 * `dashboard` and `mobile.bootstrap` are exempt: the web dashboard must render
 * the "shop closed" notice (redirecting to it is the block action, so it can't
 * be behind the lock), and the mobile app must still load to show the state.
 */
class EnsureShopAccessOpen
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user?->isOwner() || $request->routeIs('dashboard', 'mobile.bootstrap')) {
            return $next($request);
        }

        $open = $user?->shop?->preferences?->shop_access_enabled ?? true;

        if (! $open) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'shop_closed',
                    'message' => 'Shop is currently closed by the owner.',
                ], 403);
            }

            return redirect()->route('dashboard')
                ->with('error', 'Shop is currently closed by the owner. Please contact the owner to continue.');
        }

        return $next($request);
    }
}
