<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseRouteScopedSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $isPlatformRoute = $request->is('admin') || $request->is('admin/*')
            || $request->is('super-admin') || $request->is('super-admin/*');

        config([
            'session.cookie' => $isPlatformRoute
                ? config('session.platform_admin_cookie')
                : config('session.tenant_cookie'),
        ]);

        return $next($request);
    }
}
