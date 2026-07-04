<?php

namespace App\Http\Middleware;

use App\Services\ShopOpeningSetupState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Mandatory opening-setup gate. A shop cannot begin live transactional use until
 * the owner explicitly chooses a path: Start Fresh or Migrate (opening balances).
 *
 * Blocked = setup_required OR migration_in_progress (see ShopOpeningSetupState).
 *   - Owner: redirected to /onboarding so they can make/finish the decision.
 *     The owner must always be able to reach onboarding + setup routes.
 *   - Manager/staff/cashier: cannot act on onboarding, so they get a blocked
 *     page explaining that the owner must finish setup first.
 *
 * Modelled on EnsureShopAccessOpen (owner-vs-staff split, JSON-vs-redirect
 * split, fail-open on missing shop context). Applied ONLY to the main
 * authenticated tenant ERP route group — never global, never Dhiran/platform/
 * public/auth/billing-portal routes.
 */
class EnsureOpeningSetupCompleted
{
    /**
     * Routes that must stay reachable while blocked: the onboarding wizard and
     * its imports (to complete the decision), plus unrelated account/billing
     * flows that never touch the transactional ledgers. Logout, session lock and
     * profile live outside this route group and are already exempt.
     */
    private const ALLOWLIST = [
        // Dhiran (loans) is a separate product nested in the same route group but
        // has no opening-balance concept — the gate must never touch it.
        'dhiran.*',
        'onboarding.*',
        'imports.*',
        'subscription.status',
        'billing.*',
        'email.otp.*',
        'announcements.dismiss',
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($request->routeIs(...self::ALLOWLIST)) {
            return $next($request);
        }

        $user   = Auth::user();
        $shopId = $user?->shop_id ? (int) $user->shop_id : null;

        // No shop context — a different middleware owns this request.
        if ($shopId === null) {
            return $next($request);
        }

        $state = ShopOpeningSetupState::forShop($shopId);

        if ($state->isLiveAllowed()) {
            return $next($request);
        }

        // ── Blocked ──────────────────────────────────────────────────────────
        if ($user?->isOwner()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error'   => 'opening_setup_required',
                    'message' => 'Complete Opening Balance Setup or choose Start Fresh to continue.',
                ], 403);
            }

            return redirect()->route('onboarding.index');
        }

        $migrating = $state->isMigrationInProgress();

        if ($request->expectsJson()) {
            return response()->json([
                'error'   => $migrating ? 'opening_setup_in_progress' : 'opening_setup_pending',
                'message' => $migrating
                    ? 'Live billing will unlock after the owner locks opening balances.'
                    : 'The owner must complete Opening Balance Setup or choose Start Fresh before staff can use JewelFlow.',
            ], 403);
        }

        return response()->view(
            $migrating ? 'errors.opening-setup-in-progress' : 'errors.opening-setup-pending',
            [],
            403
        );
    }
}
