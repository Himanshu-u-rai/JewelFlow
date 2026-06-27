<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 2 gate: a platform admin is authenticated by password but may NOT enter
 * protected routes until they have (a) a verified email and (b) cleared the MFA
 * challenge in this session. Runs AFTER the 'admin' middleware, so the guard is
 * already authenticated here.
 *
 * The MFA-challenge and verify-email routes themselves must NOT carry this
 * middleware, or the redirect would loop.
 */
class EnsurePlatformAdminMfa
{
    public const SESSION_PASSED = 'admin_mfa_passed';
    public const SESSION_STARTED = 'admin_mfa_started_at';
    public const CHALLENGE_TTL_MINUTES = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin) {
            return redirect()->route('admin.login');
        }

        if (! $admin->hasVerifiedEmail()) {
            return redirect()->route('admin.verify-email')
                ->with('status', 'Verify your email to continue.');
        }

        if ($request->session()->get(self::SESSION_PASSED) === true) {
            return $next($request);
        }

        return redirect()->route('admin.mfa.show');
    }
}
