<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileDeviceSession;
use App\Models\Shop;
use App\Models\User;
use App\Services\Mobile\MobileSessionSeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request, MobileSessionSeatService $seatService): JsonResponse
    {
        $request->validate([
            'mobile_number' => ['required', 'string', 'digits:10'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($request->mobile_number) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'message' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ], 429);
        }

        $user = User::where('mobile_number', $request->mobile_number)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            return response()->json([
                'message' => 'Invalid mobile number or password.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is deactivated. Contact your shop owner.',
            ], 403);
        }

        if (! $user->shop_id) {
            return response()->json([
                'message' => 'No shop associated with this account. Please complete setup on the web app.',
            ], 403);
        }

        $shop = $user->shop;

        if (! $shop || ! $shop->is_active) {
            return response()->json([
                'message' => 'Your shop is currently deactivated.',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        $seat = $seatService->evaluate($shop, $user);
        if (! $seat['allowed']) {
            return response()->json([
                'code' => 'mobile_session_limit_reached',
                'message' => 'Mobile session limit reached for this shop plan.',
                'session_limit' => $seat['session_limit'],
                'active_sessions' => $seat['active_sessions'],
                'seat_scope' => $seat['seat_scope'],
            ], 403);
        }

        // Revoke existing mobile tokens for this user and end their sessions.
        // This enforces "one active session per user" — a shared device
        // must go through this flow when an operator switches accounts.
        DB::transaction(function () use ($user) {
            // End any still-active session rows so audit history is complete.
            // Use withoutTenant() because TenantContext is not set at login time
            // (no auth middleware has run yet). The explicit shop_id filter is the
            // tenant scope here.
            MobileDeviceSession::withoutTenant()
                ->where('user_id', $user->id)
                ->where('shop_id', $user->shop_id)
                ->whereNull('logged_out_at')
                ->each(function (MobileDeviceSession $s) {
                    $s->endSession('replaced');
                });

            // Revoke the Sanctum tokens (triggers nullOnDelete on session FK).
            $user->tokens()->where('name', 'mobile-app')->delete();
        });

        // Create the new session row FIRST, then mint the token and bind it.
        $session = MobileDeviceSession::create([
            'shop_id'      => $user->shop_id,
            'user_id'      => $user->id,
            'device_uuid'  => $request->input('device_uuid'),
            'device_name'  => $request->input('device_name'),
            'platform'     => $request->input('platform'),
            'app_version'  => $request->input('app_version'),
            'os_version'   => $request->input('os_version'),
            'ip_address'   => $request->ip(),
            'logged_in_at' => now(),
        ]);

        $tokenObj = $user->createToken('mobile-app');
        $plainToken = $tokenObj->plainTextToken;

        // Bind the Sanctum token to the session so EnforceSessionAlive
        // can check session state on every authenticated request.
        DB::table('personal_access_tokens')
            ->where('id', $tokenObj->accessToken->id)
            ->update(['mobile_device_session_id' => $session->id]);

        $session->update(['token_id' => $tokenObj->accessToken->id]);

        // Role must be loaded without the BelongsToShop global scope because
        // TenantContext is not set yet at login time (no auth middleware runs).
        $roleName = DB::table('roles')
            ->where('id', $user->role_id)
            ->where('shop_id', $user->shop_id)
            ->value('name');

        return response()->json([
            'token'      => $plainToken,
            'session_id' => $session->id,
            'user'       => [
                'id'            => $user->id,
                'name'          => $user->name,
                'mobile_number' => $user->mobile_number,
                'role'          => $roleName,
            ],
            'shop'       => [
                'id'       => $shop->id,
                'name'     => $shop->name,
                'type'     => $shop->shop_type,
                'logo_url' => $this->shopLogoUrl($request, $shop),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // End the bound session row BEFORE deleting the token (so last_seen_at
        // can be snapshotted from the token's last_used_at).
        $session = MobileDeviceSession::withoutTenant()
            ->where('token_id', $token->id)
            ->whereNull('logged_out_at')
            ->first();

        $session?->endSession('logout');

        $token->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role', 'shop');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile_number' => $user->mobile_number,
                'email' => $user->email,
                'role' => $user->role?->name,
                'is_active' => $user->is_active,
            ],
            'shop' => $user->shop ? [
                'id' => $user->shop->id,
                'name' => $user->shop->name,
                'type' => $user->shop->shop_type,
                'logo_url' => $this->shopLogoUrl($request, $user->shop),
            ] : null,
        ]);
    }

    private function shopLogoUrl(Request $request, Shop $shop): ?string
    {
        if (empty($shop->logo_path)) {
            return null;
        }

        $pathOnly = parse_url(Storage::disk('public')->url($shop->logo_path), PHP_URL_PATH);

        return $request->getSchemeAndHttpHost() . '/' . ltrim((string) $pathOnly, '/');
    }
}
