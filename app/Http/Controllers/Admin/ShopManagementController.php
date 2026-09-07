<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use App\Models\Repair;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use App\Support\SubscriptionTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopManagementController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index(Request $request): View
    {
        $query = Shop::query()
            ->withCount('users');

        if ($request->filled('q')) {
            $q = trim((string) $request->q);
            $query->where(function ($inner) use ($q) {
                $inner->where('name', 'ilike', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('owner_mobile', 'like', "%{$q}%");
            });
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('access_mode', 'active');
            } elseif ($request->status === 'inactive') {
                $query->whereIn('access_mode', ['read_only', 'suspended']);
            }
        }

        if ($request->filled('type')) {
            $query->where('shop_type', $request->type);
        }

        if ($request->filled('environment')) {
            $query->where('environment', $request->environment);
        }

        $shops = $query->latest()->paginate(20)->withQueryString();

        $plans = Plan::whereRaw('is_active IS TRUE')->orderBy('name')->get(['id', 'name', 'price_monthly']);

        return view('super-admin.shops.index', compact('shops', 'plans'));
    }

    public function show(Shop $shop): View
    {
        $shop->load([
            'users.role' => fn ($query) => $query->withoutTenant(),
            'editions.activatedBy',
            'editions.deactivatedBy',
        ]);

        $stats = [
            'users' => $shop->users()->count(),
            'customers' => Customer::withoutTenant()->where('shop_id', $shop->id)->count(),
            'items' => Item::withoutTenant()->where('shop_id', $shop->id)->count(),
            'invoices' => Invoice::withoutTenant()->where('shop_id', $shop->id)->count(),
            'repairs' => Repair::withoutTenant()->where('shop_id', $shop->id)->count(),
        ];

        $activeEditions   = $shop->editionList();
        $availableEditions = array_values(array_diff(\App\Support\ShopEdition::ALL, $activeEditions));
        $editionHistory   = $shop->editions()->orderByDesc('created_at')->get();

        $billingInvoices = PlatformInvoice::where('shop_id', $shop->id)
            ->with('plan')
            ->latest('issued_at')
            ->limit(10)
            ->get();

        $currentSubscription = ShopSubscription::query()
            ->where('shop_id', $shop->id)
            ->latest('id')
            ->first();

        $plans = Plan::orderBy('name')->get(['id', 'name', 'price_monthly', 'price_yearly', 'grace_days']);

        // Safe suggested term for the manual-extension form — never the stale dates
        // of an expired row. The form prefills these; the operator's edits win.
        $suggestedTerm = SubscriptionTerm::suggest(
            $currentSubscription,
            $currentSubscription?->billing_cycle ?? 'yearly'
        );

        $storageStat = \App\Models\ShopStorageStat::where('shop_id', $shop->id)->first();

        return view('super-admin.shops.show', compact(
            'shop',
            'stats',
            'activeEditions',
            'availableEditions',
            'editionHistory',
            'billingInvoices',
            'currentSubscription',
            'suggestedTerm',
            'plans',
            'storageStat'
        ));
    }

    public function updateStatus(Request $request, Shop $shop): RedirectResponse
    {
        $validated = $request->validate([
            'access_mode' => ['nullable', 'in:active,read_only,suspended'],
            'is_active' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'suspended_until' => ['nullable', 'date'],
        ]);

        $accessMode = $validated['access_mode'] ?? null;
        if ($accessMode === null && array_key_exists('is_active', $validated)) {
            $accessMode = (bool) $validated['is_active'] ? 'active' : 'suspended';
        }
        if ($accessMode === null) {
            // withInput() so the operator's typed reason survives the round-trip.
            // The form no longer pre-selects a mode, so this branch is now
            // reachable in practice — it used to be dead, because an untouched
            // form always posted the shop's stored mode back.
            return back()->withInput()->withErrors([
                'access_mode' => 'Choose Active, Read-Only or Suspended before applying a change.',
            ]);
        }

        // This generic toggle must not promise access the subscription can't
        // back — otherwise EnsureSubscriptionIsActive reconciles it straight
        // back to read_only on the very next request, making the shop look
        // like it "reverts on its own". Activation requires a subscription
        // row that genuinely entitles access today; extend/renew via Billing
        // first.
        if ($accessMode === 'active'
            && config('platform.enforce_subscriptions', false)
            && ! ShopSubscription::entitlesAccessToday($shop)
        ) {
            // withInput() only: the guard itself, its condition and its message
            // are unchanged — an unpaid shop still cannot be activated here.
            return back()->withInput()->withErrors([
                'access_mode' => "Cannot activate {$shop->name}: no subscription term currently covers today. Extend or renew the subscription via Billing first.",
            ]);
        }

        $before = $shop->only([
            'is_active',
            'access_mode',
            'suspended_at',
            'suspended_by',
            'suspension_reason',
            'suspended_until',
        ]);

        $updates = [
            'access_mode' => $accessMode,
            'is_active' => $this->dbBool($accessMode === 'active'),
            'deactivated_at' => $accessMode === 'active' ? null : now(),
        ];

        if ($accessMode === 'active') {
            $updates['suspended_at'] = null;
            $updates['suspended_by'] = null;
            $updates['suspension_reason'] = null;
            $updates['suspended_until'] = null;
        } else {
            $updates['suspended_at'] = $shop->suspended_at ?: now();
            $updates['suspended_by'] = auth('platform_admin')->id();
            $updates['suspension_reason'] = $validated['reason'] ?? $shop->suspension_reason;
            $updates['suspended_until'] = $validated['suspended_until'] ?? $shop->suspended_until;
        }

        $shop->update($updates);

        $this->audit->log(
            auth('platform_admin')->user(),
            $accessMode === 'active' ? 'shop.restore' : 'shop.suspend',
            Shop::class,
            $shop->id,
            $before,
            $shop->fresh()->only([
                'is_active',
                'access_mode',
                'suspended_at',
                'suspended_by',
                'suspension_reason',
                'suspended_until',
            ]),
            $validated['reason'] ?? null,
            $request
        );

        return back()->with('success', $accessMode === 'active' ? 'Shop activated.' : 'Shop access mode updated.');
    }

    public function export(): StreamedResponse
    {
        $filename = 'shops-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'ID', 'Name', 'Shop Type', 'Owner Mobile', 'Phone',
                'City', 'Access Mode', 'Users', 'Created At',
            ]);

            Shop::query()
                ->withCount('users')
                ->orderBy('id')
                ->each(function (Shop $shop) use ($handle) {
                    fputcsv($handle, [
                        $shop->id,
                        $shop->name,
                        $shop->shop_type,
                        $shop->owner_mobile,
                        $shop->phone,
                        $shop->city,
                        $shop->access_mode,
                        $shop->users_count,
                        $shop->created_at?->toDateTimeString(),
                    ]);
                }, 200);

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function dbBool(bool $value)
    {
        return $value;
    }
}
