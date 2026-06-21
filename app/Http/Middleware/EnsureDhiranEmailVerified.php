<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard email-verification gate for the Dhiran app.
 *
 * Dhiran accounts register with mobile + password only (no email), so a forgotten
 * password is unrecoverable — there is no address to send a reset link to. This
 * gate forces every Dhiran user to capture + verify an email (via the email-OTP
 * flow) before they can use the app. Once email_verified_at is set, the standard
 * email password-reset works.
 *
 * Allowed through while unverified: the verify-email gate page itself, the
 * email-OTP endpoints it calls, and logout. Everything else under the Dhiran
 * app redirects to the gate.
 */
class EnsureDhiranEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Only gate authenticated Dhiran-realm users. (realm:dhiran already ran.)
        if ($user && $user->realm === 'dhiran' && $user->email_verified_at === null) {
            // Let the gate page, its OTP endpoints, and logout pass.
            if ($request->routeIs('dhiran.verify-email') || $request->routeIs('dhiran.verify-email.*') || $request->routeIs('logout')) {
                return $next($request);
            }

            // Non-GET (form posts) get a 409 so client code can react; page loads redirect.
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Verify your email to continue.'], 409);
            }

            return redirect()->route('dhiran.verify-email');
        }

        return $next($request);
    }
}
