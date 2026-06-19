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

        // The Dhiran customer-facing product lives on the dhiran.* subdomain and
        // gets its OWN session cookie, so a Dhiran login and an ERP login don't
        // overwrite each other in the same browser (the session domain is shared
        // across subdomains). Platform/admin routes keep priority.
        $isDhiranHost = str_starts_with(strtolower($request->getHost()), 'dhiran.');

        $cookie = match (true) {
            $isPlatformRoute => config('session.platform_admin_cookie'),
            $isDhiranHost    => config('session.dhiran_cookie'),
            default          => config('session.tenant_cookie'),
        };

        config(['session.cookie' => $cookie]);

        return $next($request);
    }
}
