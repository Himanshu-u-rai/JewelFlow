<?php

namespace App\Http\Middleware;

use App\Services\OnboardingResumeService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureShopExists
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        // If user is NOT logged in, let normal auth flow handle it
        if (!$user) {
            return $next($request);
        }

        // These routes must ALWAYS be allowed during onboarding
        $allowedRoutes = [
            'shops.choose-type',
            'shops.create',
            'shops.store',
            'subscription.plans',
            'subscription.choose',
            'subscription.payment',
            'subscription.payment.initiate',
            'subscription.payment.callback',
            'subscription.payment.webhook',
            'logout',
        ];

        // If user has NO shop and is trying to access anything else → resume onboarding
        if (is_null($user->shop_id) && !$request->routeIs($allowedRoutes)) {
            $step = OnboardingResumeService::resolveStep($user);

            return OnboardingResumeService::redirectForStep($step);
        }

        return $next($request);
    }
}
