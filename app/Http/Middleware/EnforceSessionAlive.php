<?php

namespace App\Http\Middleware;

use App\Models\MobileDeviceSession;
use App\Services\MetalRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * M7 — EnforceSessionAlive middleware.
 *
 * Runs on every authenticated mobile request (mounted AFTER auth:sanctum
 * so the user is resolved). Checks that the Sanctum token is still bound
 * to a live MobileDeviceSession. If the session has been ended (revoked,
 * replaced, or explicitly logged out), the request is rejected with a
 * stable envelope-shaped 401 so the mobile client can distinguish "token
 * technically valid but session ended" from "bad credentials."
 *
 * Stable session-end codes the mobile client MUST handle:
 *
 *   session_ended           — catch-all; check ended_reason in params
 *   session_revoked         — owner/manager explicitly disconnected this device
 *   session_replaced        — a new login on this account ended this session
 *                             (operator switch on the same user account)
 *   session_stale_pruned    — session expired after inactivity
 *   session_terminated      — administrative termination
 *
 * Legacy tokens (mobile_device_session_id IS NULL) always pass through.
 * This preserves backward compatibility with any token minted before M7.
 *
 * On each live request, last_seen_at is touched atomically so the seat
 * service and the web dashboard can show accurate "last active" times.
 */
class EnforceSessionAlive
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token || ! isset($token->mobile_device_session_id)) {
            // Non-mobile token or legacy token — pass through.
            return $next($request);
        }

        $sessionId = $token->mobile_device_session_id;

        if ($sessionId === null) {
            // Legacy token without a bound session — pass through.
            return $next($request);
        }

        $session = MobileDeviceSession::withoutTenant()->find($sessionId);

        if (! $session) {
            // Session row was deleted (retention prune) — treat as ended.
            return $this->sessionEndedResponse($request, 'session_ended', 'Session no longer exists.');
        }

        if ($session->logged_out_at !== null) {
            $code = $this->endedReasonCode($session->ended_reason);
            $msg  = $this->endedReasonMessage($session->ended_reason);
            return $this->sessionEndedResponse($request, $code, $msg, $session->ended_reason);
        }

        // Session is alive — touch last_seen_at (fire and forget; never blocks).
        try {
            MobileDeviceSession::withoutTenant()
                ->where('id', $sessionId)
                ->whereNull('logged_out_at')
                ->update(['last_seen_at' => now()]);
        } catch (\Throwable) {
            // Non-fatal — never block a valid request because of a timestamp write.
        }

        return $next($request);
    }

    private function endedReasonCode(?string $reason): string
    {
        return match ($reason) {
            'revoked'       => 'session_revoked',
            'replaced'      => 'session_replaced',
            'stale_pruned'  => 'session_stale_pruned',
            'terminated'    => 'session_terminated',
            default         => 'session_ended',
        };
    }

    private function endedReasonMessage(?string $reason): string
    {
        return match ($reason) {
            'revoked'      => 'This session was disconnected by a manager or owner. Please sign in again.',
            'replaced'     => 'A new sign-in replaced this session. Please sign in again.',
            'stale_pruned' => 'This session expired due to inactivity. Please sign in again.',
            'terminated'   => 'This session was terminated. Please sign in again.',
            default        => 'This session has ended. Please sign in again.',
        };
    }

    private function sessionEndedResponse(
        Request $request,
        string  $code,
        string  $message,
        ?string $endedReason = null,
    ): Response {
        $requestId = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();

        $body = [
            'data'   => null,
            'meta'   => [
                'request_id'       => $requestId,
                'server_time'      => now()->toIso8601String(),
                'api_version'      => '1',
                'registry_version' => MetalRegistry::registryVersion(),
            ],
            'errors' => [[
                'code'    => $code,
                'message' => $message,
                'params'  => array_filter(['ended_reason' => $endedReason]),
            ]],
        ];

        return response()
            ->json($body, 401)
            ->withHeaders(['X-Request-Id' => $requestId]);
    }
}
