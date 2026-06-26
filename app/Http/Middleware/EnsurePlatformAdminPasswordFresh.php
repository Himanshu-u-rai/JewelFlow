<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revokes existing platform-admin sessions after a password change.
 *
 * At login we stamp the session with the admin's password_changed_at. A
 * password reset (web or CLI) bumps password_changed_at, so every other live
 * session now carries a stale stamp and is force-logged-out on its next
 * request. Works with the file session driver — no DB session enumeration
 * needed.
 */
class EnsurePlatformAdminPasswordFresh
{
    public const SESSION_KEY = 'admin_pw_stamp';

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform_admin')->user();

        if ($admin) {
            $current = optional($admin->password_changed_at)->getTimestamp() ?? 0;
            $stamped = $request->session()->get(self::SESSION_KEY);

            // Older sessions predate this middleware (null stamp) — adopt the
            // current value rather than punishing them. Only a real mismatch
            // (password changed since this session started) forces logout.
            if ($stamped === null) {
                $request->session()->put(self::SESSION_KEY, $current);
            } elseif ((int) $stamped !== (int) $current) {
                Auth::guard('platform_admin')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('admin.login')
                    ->with('status', 'Your password was changed. Please sign in again.');
            }
        }

        return $next($request);
    }
}
