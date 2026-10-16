<?php

namespace App\Http\Middleware;

use App\Models\Platform\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform maintenance (Super Admin → Settings → Maintenance Mode). Separate
 * from Laravel's `artisan down`, which deployments use.
 *
 * Runs first in both the web and the api middleware groups, so a refused
 * request never reaches authentication, idempotency claims or a controller: it
 * books nothing and leaves the app's idempotency key unused for the retry that
 * follows maintenance. It does not look at who is signed in — being a platform
 * admin is not a way to use tenant pages while tenants are locked out.
 */
class CheckMaintenanceMode
{
    /**
     * What stays reachable, each for a stated reason:
     *  - admin, super-admin (the bare paths too): admins must be able to sign
     *    in, pass MFA, reach the dashboard/Settings and switch maintenance off.
     *    Their own auth, MFA and role middleware still run on every route.
     *  - health, ping, up: uptime checks; they report, they change nothing.
     *  - subscription/payment/webhook and /callback: Razorpay telling us about
     *    money it has ALREADY taken (signature-verified, idempotent). Refusing
     *    would leave a paid subscription unrecorded.
     */
    private const OPEN = [
        'admin', 'admin/*', 'super-admin', 'super-admin/*',
        'health', 'ping', 'up',
        'subscription/payment/webhook', 'subscription/payment/callback',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! PlatformSetting::bool('maintenance_mode', false) || $request->is(...self::OPEN)) {
            return $next($request);
        }

        $message = PlatformSetting::get(
            'maintenance_message',
            'JewelFlows is temporarily down for maintenance. We\'ll be back shortly.'
        );
        $headers = ['Retry-After' => '300'];

        if ($request->is('api/mobile/v1', 'api/mobile/v1/*')) {
            // The v1 error envelope, so the app reads a stable code.
            $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();

            return response()->json([
                'data' => null,
                'meta' => ['request_id' => $requestId, 'server_time' => now()->toIso8601String(), 'api_version' => '1'],
                'errors' => [['code' => 'maintenance', 'message' => $message]],
            ], 503, $headers + ['X-Request-Id' => $requestId]);
        }

        if ($request->is('api', 'api/*') || $request->expectsJson()) {
            // Legacy shape (message, maintenance) plus the errors array the app
            // already parses, so either reader gets the code and the message.
            return response()->json([
                'message' => $message,
                'maintenance' => true,
                'errors' => [['code' => 'maintenance', 'message' => $message]],
            ], 503, $headers);
        }

        return response()->view('maintenance', compact('message'), 503, $headers);
    }
}
