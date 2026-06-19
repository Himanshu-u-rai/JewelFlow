<?php

namespace App\Http\Middleware;

use App\Services\OnboardingResumeService;
use App\Support\Realm;
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

        // Realm boundary FIRST — the product boundary is authoritative and must
        // be enforced BEFORE any shop-onboarding redirect, regardless of how the
        // middleware stack happens to be ordered for a given route.
        //
        // ERP shop-onboarding (shops.choose-type, plan selection, …) belongs to
        // the ERP product. A cross-realm request must never be funnelled into the
        // wrong product's onboarding:
        //   • a Dhiran account (on any host) → its own Dhiran home;
        //   • an ERP account on the Dhiran host (e.g. crafted /dhiran URL) → the
        //     ERP dashboard, never the Dhiran app.
        // logout is always allowed so a user can sign out from anywhere.
        if (! $request->routeIs('logout')) {
            $userRealm = Realm::of($user);
            $hostRealm = Realm::current($request);

            if ($userRealm === Realm::DHIRAN) {
                // Dhiran account: let it stay inside its own product, send it home
                // otherwise (Dhiran owns its own future onboarding).
                if (! $request->routeIs('dhiran.*')) {
                    return redirect()->route('dhiran.dashboard');
                }
            } elseif ($hostRealm === Realm::DHIRAN || $request->routeIs('dhiran.*')) {
                // ERP account reaching the Dhiran host / a Dhiran route → bounce to
                // its own realm; it never gets the Dhiran experience or onboarding.
                return redirect()->route('dashboard');
            }
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
