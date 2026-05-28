<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MobileDeviceSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class MobileDeviceSessionController extends Controller
{
    /**
     * Revoke a specific device session.
     * Gate: staff.manage — owner and manager can revoke devices.
     */
    public function destroy(Request $request, MobileDeviceSession $deviceSession): RedirectResponse
    {
        $this->authorize('staff.manage');

        // Tenant scope: ensure session belongs to the authenticated user's shop.
        if ((int) $deviceSession->shop_id !== (int) $request->user()->shop_id) {
            abort(404);
        }

        // Managers cannot disconnect the owner's device — only another owner can.
        if ($deviceSession->user?->isOwner() && ! $request->user()->isOwner()) {
            abort(403);
        }

        if ($deviceSession->logged_out_at !== null) {
            return redirect()->route('settings.edit', ['tab' => 'devices'])
                ->with('error', 'Session is already ended.');
        }

        DB::transaction(function () use ($deviceSession, $request) {
            // Snapshot last_seen_at and mark session ended BEFORE deleting the token.
            $deviceSession->endSession('revoked');

            // Delete the Sanctum token — next API request from this device gets 401.
            if ($deviceSession->token_id) {
                PersonalAccessToken::where('id', $deviceSession->token_id)->delete();
            }

            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'device_revoked',
                'model_type'  => 'MobileDeviceSession',
                'model_id'    => $deviceSession->id,
                'description' => 'Disconnected device: ' . ($deviceSession->device_name ?? 'unknown'),
                'data'        => [
                    'revoked_user_id'   => $deviceSession->user_id,
                    'device_name'       => $deviceSession->device_name,
                    'platform'          => $deviceSession->platform,
                    'logged_in_at'      => $deviceSession->logged_in_at?->toIso8601String(),
                ],
            ]);
        });

        return redirect()->route('settings.edit', ['tab' => 'devices'])
            ->with('success', 'Device disconnected successfully.');
    }

    /**
     * Revoke all active sessions for a specific staff member.
     * Gate: owner only — this is a per-user nuclear action.
     */
    public function destroyAllForUser(Request $request, User $user): RedirectResponse
    {
        if (! $request->user()->isOwner()) {
            abort(403);
        }

        // Tenant scope: user must belong to the same shop.
        if ((int) $user->shop_id !== (int) $request->user()->shop_id) {
            abort(404);
        }

        // Server-side guard: owner cannot revoke their own sessions through this endpoint.
        // The UI already hides the button, but a direct HTTP request must also be rejected.
        if ($user->id === $request->user()->id) {
            abort(403);
        }

        $sessions = MobileDeviceSession::where('shop_id', $request->user()->shop_id)
            ->where('user_id', $user->id)
            ->whereNull('logged_out_at')
            ->get();

        if ($sessions->isEmpty()) {
            return redirect()->route('settings.edit', ['tab' => 'devices'])
                ->with('error', 'No active sessions found for this user.');
        }

        DB::transaction(function () use ($sessions, $user, $request) {
            foreach ($sessions as $session) {
                $session->endSession('revoked');

                if ($session->token_id) {
                    PersonalAccessToken::where('id', $session->token_id)->delete();
                }
            }

            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'device_revoked_all',
                'model_type'  => 'User',
                'model_id'    => $user->id,
                'description' => 'Disconnected all devices for ' . ($user->name ?? $user->mobile_number),
                'data'        => [
                    'revoked_user_name' => $user->name ?? $user->mobile_number,
                    'sessions_revoked'  => $sessions->count(),
                ],
            ]);
        });

        $displayName = $user->name ?? $user->mobile_number;

        return redirect()->route('settings.edit', ['tab' => 'devices'])
            ->with('success', "All devices for {$displayName} disconnected.");
    }
}
