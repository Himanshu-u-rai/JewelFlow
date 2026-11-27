<?php

namespace App\Http\Middleware;

use App\Services\ShopPricingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side daily metal-rate gate. While the owner of an active retail shop has
 * not saved today's rates, every request in the shop route group is refused and
 * sent to the rate form, so hiding the form in the browser unlocks nothing. Only
 * the form itself, saving the rates, signing out and the caller's own session
 * pass. The app's API (api/mobile, api/mobile/v1) carries the same gate.
 */
class EnsureDailyRatesEntered
{
    private const ALLOWED = [
        'pricing.daily-rates.gate', 'settings.pricing.save-rates',
        'mobile.bootstrap', 'mobile.pricing.today', 'mobile.pricing.save-today',
        ...\App\Support\SelfServiceRoutes::SIGN_OUT,
        ...\App\Support\SelfServiceRoutes::OWN_SESSION,
    ];

    public function __construct(private ShopPricingService $pricing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->routeIs(self::ALLOWED) || ! $this->pricing->ownerMustEnterDailyRates($user)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error' => 'daily_rates_required',
                'message' => "Save today's metal rates to continue.",
            ], 423);
        }

        return redirect()->route('pricing.daily-rates.gate');
    }
}
