<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Blocks Quick Bill routes when the owner has switched the feature off for the
 * shop. Default is ON: a missing preferences row or null column reads as enabled
 * so existing shops are unaffected. Disabling never deletes data — past records
 * stay queryable and access is restored the moment it is re-enabled.
 */
class EnsureQuickBillEnabled
{
    public function handle(Request $request, Closure $next)
    {
        $shop = Auth::user()?->shop;
        $enabled = $shop?->preferences?->quick_bill_enabled ?? true;

        if (! $enabled) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'quick_bill_disabled'], 403);
            }

            return redirect()->route('dashboard')
                ->with('error', 'Quick Bill is turned off for this shop.');
        }

        return $next($request);
    }
}
