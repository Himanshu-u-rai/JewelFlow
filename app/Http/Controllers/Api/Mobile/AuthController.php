<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
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

        // Revoke existing mobile tokens for this user
        $user->tokens()->where('name', 'mobile-app')->delete();

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile_number' => $user->mobile_number,
                'role' => $user->role?->name,
            ],
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'type' => $shop->shop_type,
                'logo_url' => $this->shopLogoUrl($request, $shop),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

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
