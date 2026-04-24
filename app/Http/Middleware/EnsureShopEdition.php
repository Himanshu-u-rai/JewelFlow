<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gate routes to specific shop editions.
 *
 * Usage in routes:
 *   ->middleware('edition:manufacturer')          — manufacturer shops
 *   ->middleware('edition:retailer')              — retailer shops
 *   ->middleware('edition:retailer,manufacturer') — either (OR semantics)
 *
 * A shop with multiple editions (e.g. retailer + dhiran) passes any gate
 * that matches ANY of its editions — which is the correct default for
 * feature gates post-editions-refactor. Routes that need AND semantics
 * (rare) should compose multiple middleware entries.
 */
class EnsureShopEdition
{
    public function handle(Request $request, Closure $next, string ...$editions)
    {
        $user = $request->user();

        if (!$user || !$user->shop_id) {
            return $next($request);
        }

        $shop = $user->shop;

        if (!$shop || !$shop->hasAnyEdition(...$editions)) {
            abort(403, 'This feature is not available for your shop.');
        }

        return $next($request);
    }
}
