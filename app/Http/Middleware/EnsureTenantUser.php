<?php

namespace App\Http\Middleware;

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

        TenantContext::set(auth()->user()->shop_id);
        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
