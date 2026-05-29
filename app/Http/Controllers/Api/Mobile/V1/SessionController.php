<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MobileDeviceSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mobile v1 — Session governance (M7).
 *
 * Endpoints:
 *
 *   GET    /sessions            — list active sessions for this shop (owner/manager)
 *   POST   /sessions/lock       — lock screen: mark current session locked
 *   POST   /sessions/unlock     — unlock screen: verify PIN/password, clear lock
 *   DELETE /sessions/{session}  — revoke a specific session (owner/manager)
 *   DELETE /sessions            — revoke all sessions for a specified user
 *   GET    /sessions/me         — current session detail + operator context
 *
 * Shared-device safety design:
 *
 *   - lock/unlock gives cashier-switch UI state WITHOUT ending the session.
 *     The server records locked_at; the mobile client uses this to require
 *     re-authentication before the next action. The token is still valid —
 *     the lock is a UX gate, not a security revocation.
 *
 *   - revoke DOES end the session server-side. The next API call from the
 *     revoked device gets 401 session_revoked via EnforceSessionAlive.
 *
 *   - operator context (/sessions/me) returns who is acting on this device
 *     right now. Mobile screens can display this prominently to prevent
 *     "wrong person finished the billing" scenarios.
 */
class SessionController extends Controller
{
    // ────────────────────────────────────────────────────────────────────
    // OPERATOR CONTEXT
    // ────────────────────────────────────────────────────────────────────

    /**
     * GET /api/mobile/v1/sessions/me
     *
     * Returns the current operator's session and identity so the app can
     * display "acting as: Priya" on every screen. Critical for shared tablets.
     */
    public function me(Request $request): JsonResponse
    {
        $user    = $request->user();
        $token   = $user->currentAccessToken();
        $session = $token->mobile_device_session_id
            ? MobileDeviceSession::find($token->mobile_device_session_id)
            : null;

        $roleName = DB::table('roles')
            ->where('id', $user->role_id)
            ->where('shop_id', $user->shop_id)
            ->value('name');

        return response()->json([
            'operator' => [
                'id'     => $user->id,
                'name'   => $user->name,
                'mobile' => $user->mobile_number,
                'role'   => $roleName,
            ],
            'session' => $session ? $this->presentSession($session, $session->id) : null,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // LOCK / UNLOCK (shared-device cashier handoff)
    // ────────────────────────────────────────────────────────────────────

    /**
     * POST /api/mobile/v1/sessions/lock
     *
     * Records that this session is in "lock screen" mode. The token remains
     * valid — the lock is a client UX gate, not a server revocation.
     * On unlock, the server clears locked_at.
     */
    public function lock(Request $request): JsonResponse
    {
        $token     = $request->user()->currentAccessToken();
        $sessionId = $token->mobile_device_session_id;

        if ($sessionId) {
            MobileDeviceSession::withoutTenant()
                ->where('id', $sessionId)
                ->whereNull('logged_out_at')
                ->update(['locked_at' => now()]);
        }

        return response()->json(['locked' => true], 200);
    }

    /**
     * POST /api/mobile/v1/sessions/unlock
     *
     * Verifies the current user's password to clear the lock-screen state.
     * Requires: { password: '...' }
     */
    public function unlock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($validated['password'], $request->user()->password)) {
            return response()->json([
                'errors' => [[
                    'code'    => 'unlock_failed',
                    'message' => 'Incorrect password. Please try again.',
                ]],
            ], 422);
        }

        $token     = $request->user()->currentAccessToken();
        $sessionId = $token->mobile_device_session_id;

        if ($sessionId) {
            MobileDeviceSession::withoutTenant()
                ->where('id', $sessionId)
                ->whereNull('logged_out_at')
                ->update(['locked_at' => null]);
        }

        return response()->json(['locked' => false], 200);
    }

    // ────────────────────────────────────────────────────────────────────
    // ACTIVE SESSION LISTING
    // ────────────────────────────────────────────────────────────────────

    /**
     * GET /api/mobile/v1/sessions
     *
     * Returns all active (not logged-out) sessions for the current shop.
     * Gate: returns.approve (owner/manager). Staff can only see their own.
     */
    public function index(Request $request): JsonResponse
    {
        $shopId  = (int) $request->user()->shop_id;
        $isOwner = $request->user()->isOwner();
        $canViewAll = $isOwner || $request->user()->can('returns.approve');

        $query = MobileDeviceSession::where('shop_id', $shopId)
            ->whereNull('logged_out_at')
            ->with('user:id,name,mobile_number,role_id')
            ->orderByDesc('logged_in_at');

        if (! $canViewAll) {
            $query->where('user_id', $request->user()->id);
        }

        $currentTokenId = $request->user()->currentAccessToken()->id;
        $sessions = $query->get();

        return response()->json([
            'data' => $sessions->map(fn ($s) => $this->presentSession($s, $currentTokenId))->values(),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // REVOCATION
    // ────────────────────────────────────────────────────────────────────

    /**
     * DELETE /api/mobile/v1/sessions/{session}
     *
     * Revoke a specific session. The next request from that device gets
     * 401 session_revoked. Gate: returns.approve or owner.
     */
    public function destroy(Request $request, MobileDeviceSession $session): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        abort_if($session->shop_id !== $shopId, 404);

        $canRevoke = $request->user()->isOwner()
            || $request->user()->can('returns.approve');

        if (! $canRevoke) {
            return response()->json([
                'errors' => [['code' => 'permission_denied', 'message' => 'You do not have permission to revoke sessions.']],
            ], 403);
        }

        // Managers cannot revoke the owner's sessions.
        if (! $request->user()->isOwner() && $session->user?->isOwner()) {
            return response()->json([
                'errors' => [['code' => 'permission_denied', 'message' => 'Only an owner can revoke another owner\'s session.']],
            ], 403);
        }

        if ($session->logged_out_at !== null) {
            return response()->json([
                'errors' => [['code' => 'session_already_ended', 'message' => 'This session has already ended.']],
            ], 409);
        }

        DB::transaction(function () use ($session, $request) {
            $session->endSession('revoked');

            if ($session->token_id) {
                PersonalAccessToken::where('id', $session->token_id)->delete();
            }

            AuditLog::create([
                'shop_id'    => $session->shop_id,
                'user_id'    => $request->user()->id,
                'action'     => 'mobile_session_revoked',
                'model_type' => 'MobileDeviceSession',
                'model_id'   => $session->id,
                'data'       => [
                    'revoked_user_id' => $session->user_id,
                    'device_name'     => $session->device_name,
                    'platform'        => $session->platform,
                ],
            ]);
        });

        return response()->json(['revoked' => true], 200);
    }

    /**
     * DELETE /api/mobile/v1/sessions
     *
     * Revoke ALL active sessions for a specific user_id (passed in body).
     * Gate: owner only. Prevents a manager from locking out all colleagues.
     */
    public function destroyForUser(Request $request): JsonResponse
    {
        abort_unless($request->user()->isOwner(), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $targetUserId = (int) $validated['user_id'];
        $shopId       = (int) $request->user()->shop_id;

        // Scope to shop.
        $targetUser = User::where('shop_id', $shopId)->find($targetUserId);
        if (! $targetUser) {
            return response()->json([
                'errors' => [['code' => 'not_found', 'message' => 'User not found in this shop.']],
            ], 404);
        }

        // Owner cannot revoke themselves via this endpoint (use logout).
        if ($targetUser->id === $request->user()->id) {
            return response()->json([
                'errors' => [['code' => 'self_revoke_forbidden', 'message' => 'Use /auth/logout to end your own session.']],
            ], 422);
        }

        $sessions = MobileDeviceSession::where('shop_id', $shopId)
            ->where('user_id', $targetUserId)
            ->whereNull('logged_out_at')
            ->get();

        DB::transaction(function () use ($sessions, $request, $targetUser, $shopId) {
            foreach ($sessions as $session) {
                $session->endSession('revoked');
                if ($session->token_id) {
                    PersonalAccessToken::where('id', $session->token_id)->delete();
                }
            }

            AuditLog::create([
                'shop_id'    => $shopId,
                'user_id'    => $request->user()->id,
                'action'     => 'mobile_sessions_revoked_all',
                'model_type' => 'User',
                'model_id'   => $targetUser->id,
                'data'       => [
                    'revoked_user_name' => $targetUser->name,
                    'sessions_revoked'  => $sessions->count(),
                ],
            ]);
        });

        return response()->json([
            'revoked'          => true,
            'sessions_revoked' => $sessions->count(),
        ], 200);
    }

    // ────────────────────────────────────────────────────────────────────
    // Presentation
    // ────────────────────────────────────────────────────────────────────

    private function presentSession(MobileDeviceSession $s, int|null $currentTokenId = null): array
    {
        return [
            'id'            => $s->id,
            'device_name'   => $s->device_name,
            'platform'      => $s->platform,
            'app_version'   => $s->app_version,
            'is_current'    => $s->token_id !== null && $s->token_id === $currentTokenId,
            'is_locked'     => $s->locked_at !== null,
            'locked_at'     => optional($s->locked_at)->toIso8601String(),
            'logged_in_at'  => optional($s->logged_in_at)->toIso8601String(),
            'last_seen_at'  => optional($s->last_seen_at)->toIso8601String(),
            'operator'      => $s->user ? [
                'id'     => $s->user->id,
                'name'   => $s->user->name,
                'mobile' => $s->user->mobile_number,
            ] : null,
        ];
    }
}
