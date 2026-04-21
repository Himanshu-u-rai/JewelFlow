<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure the Dhiran edition is active for the current shop.
 *
 * Reads from shop_editions (via Shop::hasEdition) post Phase 2 of the
 * editions refactor. DhiranSettings.is_enabled still mirrors the same
 * state via a model event, so this check and the settings row stay in
 * lockstep without this middleware needing to load the settings row.
 */
class EnsureDhiranEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->shop_id) {
            abort(403, 'No shop assigned.');
        }

        if (! $user->shop?->hasEdition('dhiran')) {
            return redirect()->route('dhiran.dashboard');
        }

        return $next($request);
    }
}
