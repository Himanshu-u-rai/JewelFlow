<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Shop;
use App\Models\ShopEditionRequest;
use App\Services\PlatformAuditService;
use App\Support\ShopEdition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin queue for /settings/services requests from shop owners.
 *
 * Approve → grants (or revokes) the edition using the same helpers as the
 * direct admin UI, records the review, and writes a platform_audit_logs entry.
 * Deny → records the review without touching editions.
 */
class ShopEditionRequestController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', 'pending');

        $query = ShopEditionRequest::query()
            ->withoutGlobalScope('shop')
            ->with(['shop', 'user', 'reviewer']);

        if (in_array($status, ['pending', 'approved', 'denied', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $requests = $query->latest()->orderByDesc('id')->paginate(25)->withQueryString();

        $pendingCount = ShopEditionRequest::withoutGlobalScope('shop')
            ->where('status', 'pending')
            ->count();

        return view('super-admin.edition-requests.index', compact('requests', 'status', 'pendingCount'));
    }

    public function approve(Request $request, ShopEditionRequest $editionRequest): RedirectResponse
    {
        $editionRequest = $this->loadCrossTenant($editionRequest);

        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $editionRequest->shop) {
            return back()->with('error', 'Shop no longer exists.');
        }

        $notes = $validated['review_notes'] ?? null;
        $admin = auth('platform_admin')->user();

        $decided = ShopEditionRequest::decidePending($editionRequest->id, function (ShopEditionRequest $editionRequest) use ($notes, $admin, $request) {
            [$shop, $edition] = [$editionRequest->shop, $editionRequest->edition];
            if ($editionRequest->action === ShopEditionRequest::ACTION_ADD) {
                if (! $shop->hasEdition($edition)) {
                    ShopEdition::grantTo($shop, $edition, null);
                    if ($edition === ShopEdition::DHIRAN) {
                        DhiranSettings::withoutGlobalScope('shop')
                            ->where('shop_id', $shop->id)->update(['is_enabled' => DB::raw('true')]);
                    }
                }
            } else {
                if ($shop->hasEdition($edition) && count($shop->editionList()) > 1) {
                    ShopEdition::revokeFrom($shop, $edition, null, $editionRequest->reason);
                    if ($edition === ShopEdition::DHIRAN) {
                        DhiranSettings::withoutGlobalScope('shop')
                            ->where('shop_id', $shop->id)->update(['is_enabled' => DB::raw('false')]);
                    }
                }
            }

            $editionRequest->status       = ShopEditionRequest::STATUS_APPROVED;
            $editionRequest->reviewed_by  = $admin?->id;
            $editionRequest->reviewed_at  = now();
            $editionRequest->review_notes = $notes;
            $editionRequest->save();

            $this->audit->log(
                $admin,
                'shop.edition.request.approved',
                Shop::class,
                $shop->id,
                ['action' => $editionRequest->action, 'edition' => $edition],
                ['status' => 'approved'],
                $notes ?? $editionRequest->reason,
                $request
            );
        });

        if (! $decided) {
            return back()->with('error', 'This request was already decided.');
        }

        return back()->with('success', 'Request approved and edition updated.');
    }

    public function deny(Request $request, ShopEditionRequest $editionRequest): RedirectResponse
    {
        $editionRequest = $this->loadCrossTenant($editionRequest);

        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $decided = ShopEditionRequest::decidePending($editionRequest->id, function (ShopEditionRequest $editionRequest) use ($validated, $request) {
            $editionRequest->status       = ShopEditionRequest::STATUS_DENIED;
            $editionRequest->reviewed_by  = auth('platform_admin')->id();
            $editionRequest->reviewed_at  = now();
            $editionRequest->review_notes = $validated['review_notes'];
            $editionRequest->save();

            $this->audit->log(
                auth('platform_admin')->user(),
                'shop.edition.request.denied',
                Shop::class,
                $editionRequest->shop_id,
                ['action' => $editionRequest->action, 'edition' => $editionRequest->edition],
                ['status' => 'denied'],
                $validated['review_notes'],
                $request
            );
        });

        if (! $decided) {
            return back()->with('error', 'This request was already decided.');
        }

        return back()->with('success', 'Request denied.');
    }

    /**
     * Route-model binding re-applies the tenant scope, so reload the request
     * without it (admins operate cross-tenant).
     */
    private function loadCrossTenant(ShopEditionRequest $editionRequest): ShopEditionRequest
    {
        return ShopEditionRequest::withoutGlobalScope('shop')
            ->with(['shop', 'user'])
            ->findOrFail($editionRequest->id);
    }
}
