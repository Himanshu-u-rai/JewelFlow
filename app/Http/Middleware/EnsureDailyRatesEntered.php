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
 * the form itself and saving the rates pass (logout lives outside this group).
 */
class EnsureDailyRatesEntered
{
    private const ALLOWED = ['pricing.daily-rates.gate', 'settings.pricing.save-rates'];

    public function __construct(private ShopPricingService $pricing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->routeIs(self::ALLOWED) || ! $this->pricing->ownerMustEnterDailyRates($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => "Save today's metal rates to continue.",
                'reason' => 'daily_rates_required',
            ], 423);
        }

        return redirect()->route('pricing.daily-rates.gate');
    }
}
