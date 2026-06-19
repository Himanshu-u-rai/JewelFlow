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
        // Decide on the HOST, not the route name. This middleware can run before
        // route binding (route() is null at this point), so routeIs() is unreliable
        // here — but the host is always known and is the authoritative realm signal.
        if (! $request->routeIs('logout')) {
            $userRealm = Realm::of($user);
            $hostRealm = Realm::current($request);

            if ($userRealm === Realm::DHIRAN && $hostRealm !== Realm::DHIRAN) {
                // Dhiran account on the ERP host → its own realm; never the ERP
                // onboarding flow.
                return redirect()->route('dhiran.dashboard');
            }

            if ($userRealm !== Realm::DHIRAN && $hostRealm === Realm::DHIRAN) {
                // ERP account on the Dhiran host (e.g. crafted /dhiran URL) → bounce
                // to its own realm; it never gets the Dhiran experience.
                return redirect()->route('dashboard');
            }

            // Dhiran account on the Dhiran host → inside its own product.
            if ($userRealm === Realm::DHIRAN) {
                // A shopless Dhiran user has no role/permissions yet, so the
                // dashboard's can:dhiran.view gate would 403 before onboarding.
                // Route them into the Dhiran onboarding flow here (this runs before
                // Authorize). The onboarding routes themselves are NOT in this
                // middleware group, so they are reachable.
                if (is_null($user->shop_id)) {
                    if (\App\Services\OnboardingResumeService::findPendingSubscription($user)) {
                        return redirect()->route('dhiran.onboarding');
                    }

                    return redirect()->route('dhiran.plans');
                }

                return $next($request);
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
