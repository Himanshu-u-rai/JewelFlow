<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Services\PlatformAuditService;
use App\Support\ShopEdition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin-side grant/revoke of shop editions.
 *
 * Part of Phase 4 of the editions refactor. Each action writes a row in
 * shop_editions AND a platform_audit_logs entry (admin_id, action, reason,
 * ip). Reason is mandatory so there's always a trail for customer-care
 * changes.
 *
 * Guards match the user-side guards in Phase 5:
 *   - cannot revoke if it would leave the shop with zero editions
 *   - revoking 'dhiran' while active loans exist is blocked (data integrity)
 *   - revoking 'retailer' / 'manufacturer' is allowed but logs explicit reason
 *
 * Gated by permission 'shops.editions.manage' via admin middleware.
 */
class ShopEditionManagementController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function grant(Request $request, Shop $shop): RedirectResponse
    {
        $validated = $request->validate([
            'edition' => ['required', 'string', Rule::in(ShopEdition::ALL)],
            'reason'  => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $edition = $validated['edition'];
        $reason  = trim($validated['reason']);

        $existing = ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('edition', $edition)
            ->first();

        if ($existing && $existing->isActive()) {
            return back()->with('error', 'Shop already has '.$edition.' edition.');
        }

        $before = $existing ? $existing->only(['deactivated_at', 'deactivation_reason', 'deactivated_by']) : null;

        DB::transaction(function () use ($shop, $edition, $reason) {
            ShopEdition::grantTo($shop, $edition, null);

            if ($edition === ShopEdition::DHIRAN) {
                \App\Models\Dhiran\DhiranSettings::getForShop($shop->id)
                    ->update(['is_enabled' => true]);
            }
        });

        $this->audit->log(
            auth('platform_admin')->user(),
            'shop.edition.grant',
            Shop::class,
            $shop->id,
            $before,
            ['edition' => $edition, 'active' => true],
            $reason,
            $request
        );

        return back()->with('success', ucfirst($edition).' edition granted to '.$shop->name.'.');
    }

    public function revoke(Request $request, Shop $shop): RedirectResponse
    {
        $validated = $request->validate([
            'edition' => ['required', 'string', Rule::in(ShopEdition::ALL)],
            'reason'  => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $edition = $validated['edition'];
        $reason  = trim($validated['reason']);

        $active = ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('edition', $edition)
            ->whereNull('deactivated_at')
            ->first();

        if (! $active) {
            return back()->with('error', 'Shop does not have '.$edition.' edition active.');
        }

        $currentActive = $shop->activeEditions()->count();
        if ($currentActive <= 1) {
            return back()->with('error', 'Cannot revoke the only active edition. Suspend the shop instead.');
        }

        if ($guard = $this->dataGuard($shop, $edition)) {
            return back()->with('error', $guard);
        }

        DB::transaction(function () use ($shop, $edition, $reason) {
            ShopEdition::revokeFrom($shop, $edition, null, $reason);

            if ($edition === ShopEdition::DHIRAN) {
                \App\Models\Dhiran\DhiranSettings::where('shop_id', $shop->id)
                    ->update(['is_enabled' => false]);
            }
        });

        $this->audit->log(
            auth('platform_admin')->user(),
            'shop.edition.revoke',
            Shop::class,
            $shop->id,
            ['edition' => $edition, 'active' => true],
            ['edition' => $edition, 'active' => false],
            $reason,
            $request
        );

        return back()->with('success', ucfirst($edition).' edition revoked from '.$shop->name.'.');
    }

    /**
     * Block a revoke if removing the edition would orphan live data.
     * Returns an error message string, or null if safe to proceed.
     */
    private function dataGuard(Shop $shop, string $edition): ?string
    {
        if ($edition === ShopEdition::DHIRAN) {
            $active = DB::table('dhiran_loans')
                ->where('shop_id', $shop->id)
                ->whereIn('status', ['active', 'renewed'])
                ->exists();
            if ($active) {
                return 'Cannot revoke Dhiran: shop has active or renewed loans. Close all loans first.';
            }
        }

        return null;
    }
}
