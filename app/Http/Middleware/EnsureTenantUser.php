<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

class EnsureTenantUser
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            TenantContext::clear();
            return $next($request);
        }

        // Fail closed on a non-tenant principal. Tenant routes are for shop
        // `User`s only; if some other authenticated principal is the active
        // guard here (e.g. a PlatformAdmin), deny cleanly with 403 instead of
        // letting downstream tenant middleware assume a User and blow up with a
        // 500 (Realm::of()/EnsureShopExists are typed to App\Models\User).
        if (! (auth()->user() instanceof User)) {
            abort(403, 'This area is for shop accounts only.');
        }

        TenantContext::set(auth()->user()->shop_id);
        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
