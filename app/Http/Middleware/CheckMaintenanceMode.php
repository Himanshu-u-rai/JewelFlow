<?php

namespace App\Http\Middleware;

use App\Models\Platform\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        // Bypass for admin panel routes and health checks
        if ($request->is('admin/*') || $request->is('super-admin/*')
            || $request->is('health') || $request->is('ping')) {
            return $next($request);
        }

        // Bypass for logged-in platform admins accessing via impersonation
        if (auth('platform_admin')->check()) {
            return $next($request);
        }

        if (PlatformSetting::bool('maintenance_mode', false)) {
            $message = PlatformSetting::get(
                'maintenance_message',
                'JewelFlow is temporarily down for maintenance. We\'ll be back shortly.'
            );

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'maintenance' => true], 503);
            }

            return response()->view('maintenance', compact('message'), 503);
        }

        return $next($request);
    }
}
