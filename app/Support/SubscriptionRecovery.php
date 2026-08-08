<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single source of truth for how a locked shop is presented to the user, so the
 * two middlewares (EnsureSubscriptionIsActive, EnsureAccountIsActive) and any
 * other gate respond IDENTICALLY regardless of which one fires first. A
 * subscription lapse is recoverable (owner → plan picker, staff → owner-must-renew,
 * API → SUBSCRIPTION_REQUIRED); an administrative suspension is a Contact-Support
 * dead end (API → SHOP_SUSPENDED) that payment can never lift.
 */
class SubscriptionRecovery
{
    /** Mobile/API stable code: subscription lapsed, self-service renew is possible. */
    public const CODE_SUBSCRIPTION_REQUIRED = 'SUBSCRIPTION_REQUIRED';

    /** Mobile/API stable code: administrative suspension, contact support (distinct). */
    public const CODE_SHOP_SUSPENDED = 'SHOP_SUSPENDED';

    /**
     * Recoverable subscription lapse. Owner is NOT logged out (subscription.*
     * routes are bypass-listed by both middlewares, so no redirect loop). Staff
     * cannot purchase — logged out with an owner-must-renew note (no plan/payment
     * leakage). API keeps the 403 forbidden contract with a stable code.
     */
    public static function recover(Request $request)
    {
        if (self::wantsJson($request)) {
            return response()->json([
                'code' => self::CODE_SUBSCRIPTION_REQUIRED,
                'message' => 'Your subscription has ended. Renew a plan to restore access.',
            ], Response::HTTP_FORBIDDEN);
        }

        $user = Auth::user();
        if ($user && $user->isShopOwner()) {
            return redirect()->route('subscription.plans')->with(
                'error',
                'Your subscription has ended. Choose a plan to restore access to your shop.'
            );
        }

        return self::logoutTo(
            $request,
            'Your shop owner must renew the subscription to restore access.'
        );
    }

    /**
     * Administrative (Contact-Support) suspension. Never self-service recoverable;
     * a distinct stable code lets the mobile app show a support screen rather than
     * a renew flow.
     */
    public static function denyAdministrative(
        Request $request,
        string $message = 'This shop is suspended by platform admin. Please contact support.'
    ) {
        if (self::wantsJson($request)) {
            return response()->json([
                'code' => self::CODE_SHOP_SUSPENDED,
                'message' => $message,
            ], Response::HTTP_FORBIDDEN);
        }

        return self::logoutTo($request, $message);
    }

    private static function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*');
    }

    private static function logoutTo(Request $request, string $message)
    {
        Auth::guard('web')->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect('/login')->withErrors(['mobile_number' => $message]);
    }
}
