<?php

namespace App\Http\Middleware;

use App\Models\Dhiran\DhiranSettings;
use App\Support\NotAvailable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dhiran access by plan (owner 2026-11-12).
 *
 *  - Plan active (shop_editions has 'dhiran'): everything.
 *  - Plan ended, Dhiran set up before (DhiranSettings.is_enabled): collect-only. The books stay readable and existing
 *    loans can be settled (payments, release, pre-close, close, cancel, activation, notice, forfeiture, evidence), so
 *    a borrower's money and gold are never stuck behind the shop's software bill. New business (new loans, renewals,
 *    new borrowers, settings) waits for renewal. Only the owner is told why (NotAvailable::unlessOwner).
 *  - Dhiran never set up: back to the dashboard, which offers activation.
 */
class EnsureDhiranEnabled
{
    /** Writes that settle loans already on the books; every other write needs an active plan. */
    private const COLLECT_ONLY_WRITES = [
        'dhiran.pay-interest', 'dhiran.repay', 'dhiran.release-item', 'dhiran.pre-close', 'dhiran.close', 'dhiran.cancel',
        'dhiran.activate-loan', 'dhiran.send-notice', 'dhiran.forfeit', 'dhiran.attachments.store',
    ];

    /** Pages that only exist to start new business. */
    private const NEW_BUSINESS_PAGES = ['dhiran.create', 'dhiran.borrowers.create', 'dhiran.old-loans.create'];

    public const LAPSED = 'Your Dhiran plan has ended. Renew it to start new loans, renewals or borrowers; collections on existing loans stay open.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->shop_id) {
            abort(403, 'No shop assigned.');
        }

        if ($user->shop?->hasEdition('dhiran')) {
            return $next($request);
        }

        if (! DhiranSettings::getForShop((int) $user->shop_id)->is_enabled) {
            return redirect()->route('dhiran.dashboard');
        }

        $allowed = $request->isMethodSafe()
            ? ! $request->routeIs(...self::NEW_BUSINESS_PAGES)
            : $request->routeIs(...self::COLLECT_ONLY_WRITES);

        return $allowed
            ? $next($request)
            : redirect()->route('dhiran.dashboard')->with('error', NotAvailable::unlessOwner(self::LAPSED, $user));
    }
}
