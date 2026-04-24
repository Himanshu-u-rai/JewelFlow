<?php

namespace App\Http\Controllers;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Platform\PlatformSetting;
use App\Models\ShopEditionAssignment;
use App\Models\ShopEditionRequest;
use App\Support\ShopEdition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Owner-facing /settings/services page.
 *
 * Remove is self-serve (with guards). Add routes through a pending request
 * that a platform admin reviews in Admin → Shops → editions.
 *
 * This split mirrors the Phase 4 admin UI: removes are lossless so the
 * owner can do them, but adds involve billing/plan changes so they go
 * through review until Phase 5b wires a checkout-flow for self-serve adds.
 */
class ShopServicesController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $shop = $user->shop;

        abort_unless($shop, 403);

        $active = $shop->editionList();
        $all    = ShopEdition::ALL;
        $platformEnabled = PlatformSetting::enabledShopTypes();

        $available = array_values(array_intersect(
            array_diff($all, $active),
            $platformEnabled
        ));

        $pendingRequests = ShopEditionRequest::where('shop_id', $shop->id)
            ->where('status', ShopEditionRequest::STATUS_PENDING)
            ->latest()
            ->get();

        $history = ShopEditionRequest::where('shop_id', $shop->id)
            ->whereIn('status', [
                ShopEditionRequest::STATUS_APPROVED,
                ShopEditionRequest::STATUS_DENIED,
                ShopEditionRequest::STATUS_CANCELLED,
            ])
            ->latest()
            ->limit(10)
            ->get();

        $assignments = ShopEditionAssignment::where('shop_id', $shop->id)
            ->whereNull('deactivated_at')
            ->get()
            ->keyBy('edition');

        return view('settings.services', [
            'shop'            => $shop,
            'active'          => $active,
            'available'       => $available,
            'pendingRequests' => $pendingRequests,
            'history'         => $history,
            'assignments'     => $assignments,
        ]);
    }

    /**
     * Owner requests to add an edition. Creates a pending request for admin review.
     */
    public function requestAdd(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $shop = $user->shop;

        abort_unless($shop, 403);

        $validated = $request->validate([
            'edition' => ['required', 'string', Rule::in(ShopEdition::ALL)],
            'reason'  => ['required', 'string', 'min:10', 'max:500'],
        ]);

        if (! in_array($validated['edition'], PlatformSetting::enabledShopTypes(), true)) {
            return back()->with('error', ucfirst($validated['edition']).' is not currently available for new activations.');
        }

        if ($shop->hasEdition($validated['edition'])) {
            return back()->with('error', 'Your shop already has '.$validated['edition'].'.');
        }

        $hasPending = ShopEditionRequest::where('shop_id', $shop->id)
            ->where('edition', $validated['edition'])
            ->where('action', ShopEditionRequest::ACTION_ADD)
            ->where('status', ShopEditionRequest::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            return back()->with('error', 'A request to add '.$validated['edition'].' is already pending review.');
        }

        ShopEditionRequest::create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'action'  => ShopEditionRequest::ACTION_ADD,
            'edition' => $validated['edition'],
            'reason'  => trim($validated['reason']),
            'status'  => ShopEditionRequest::STATUS_PENDING,
        ]);

        return back()->with('success', 'Request submitted. Our team will review and contact you within 1 business day.');
    }

    /**
     * Owner removes an edition. Self-serve if data guards pass; otherwise
     * falls through to a remove-request that support coordinates.
     */
    public function remove(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $shop = $user->shop;

        abort_unless($shop, 403);

        $validated = $request->validate([
            'edition'   => ['required', 'string', Rule::in(ShopEdition::ALL)],
            'reason'    => ['required', 'string', 'min:4', 'max:500'],
            'confirm'   => ['required', 'accepted'],
        ]);

        $edition = $validated['edition'];

        if (! $shop->hasEdition($edition)) {
            return back()->with('error', ucfirst($edition).' is not currently active for your shop.');
        }

        if (count($shop->editionList()) <= 1) {
            return back()->with('error', 'You cannot remove the only active service. Contact support to cancel your subscription instead.');
        }

        if ($guard = $this->dataGuard($shop, $edition)) {
            // Data guard hit — file a remove-request so support can help.
            ShopEditionRequest::create([
                'shop_id' => $shop->id,
                'user_id' => $user->id,
                'action'  => ShopEditionRequest::ACTION_REMOVE,
                'edition' => $edition,
                'reason'  => $validated['reason']." [blocked: {$guard}]",
                'status'  => ShopEditionRequest::STATUS_PENDING,
            ]);

            return back()->with('error', $guard.' Our team has been notified and will contact you to help close this out.');
        }

        DB::transaction(function () use ($shop, $edition, $user, $validated) {
            ShopEdition::revokeFrom($shop, $edition, null, $validated['reason']);

            if ($edition === ShopEdition::DHIRAN) {
                DhiranSettings::where('shop_id', $shop->id)
                    ->update(['is_enabled' => false]);
            }
        });

        return back()->with('success', ucfirst($edition).' has been removed from your shop.');
    }

    /**
     * Owner cancels their own pending request.
     */
    public function cancelRequest(Request $request, ShopEditionRequest $editionRequest): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($editionRequest->shop_id === $user->shop_id, 403);
        abort_unless($editionRequest->isPending(), 422, 'Only pending requests can be cancelled.');

        $editionRequest->update([
            'status'      => ShopEditionRequest::STATUS_CANCELLED,
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Request cancelled.');
    }

    private function dataGuard($shop, string $edition): ?string
    {
        if ($edition === ShopEdition::DHIRAN) {
            $active = DB::table('dhiran_loans')
                ->where('shop_id', $shop->id)
                ->whereIn('status', ['active', 'renewed'])
                ->exists();
            if ($active) {
                return 'Cannot remove Dhiran while you have active or renewed loans.';
            }
        }

        return null;
    }
}
