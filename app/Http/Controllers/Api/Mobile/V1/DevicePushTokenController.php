<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileDeviceSession;
use App\Services\Mobile\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registers / clears the push token for the CURRENT device session.
 *
 * Security: the token is always bound to the session resolved from the caller's
 * own Sanctum token (currentAccessToken()->mobile_device_session_id). The
 * request body carries no session/user/shop id, so a client cannot register a
 * token onto another device, user, or tenant. Returns are raw data — the
 * mobile.envelope middleware wraps them into { data, meta, errors }.
 */
class DevicePushTokenController extends Controller
{
    public function __construct(private PushNotificationService $push) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => 'required|string|max:255',
            'provider'   => 'nullable|in:expo',
        ]);

        $token    = trim($validated['push_token']);
        $provider = $validated['provider'] ?? PushNotificationService::PROVIDER_EXPO;

        if (! $this->push->isValidExpoToken($token)) {
            return response()->json([
                'errors' => [[
                    'code'    => 'invalid_push_token',
                    'message' => 'Push token is not a valid Expo token.',
                ]],
            ], 422);
        }

        $session = $this->currentSession($request);
        if (! $session) {
            return response()->json([
                'errors' => [[
                    'code'    => 'session_required',
                    'message' => 'A live device session is required to register for push.',
                ]],
            ], 409);
        }

        $session->setPushToken($token, $provider);

        return response()->json([
            'registered' => true,
            'provider'   => $provider,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->currentSession($request)?->clearPushToken();

        return response()->json(['registered' => false]);
    }

    /**
     * The live session backing the caller's current token. Null for legacy
     * tokens (pre-session-binding) — those cannot register for push.
     */
    private function currentSession(Request $request): ?MobileDeviceSession
    {
        $sessionId = $request->user()?->currentAccessToken()?->mobile_device_session_id;

        if (! $sessionId) {
            return null;
        }

        return MobileDeviceSession::withoutTenant()
            ->whereNull('logged_out_at')
            ->find($sessionId);
    }
}
