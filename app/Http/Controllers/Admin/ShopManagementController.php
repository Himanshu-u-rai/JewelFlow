<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Repair;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

        $shops = $query->latest()->paginate(20)->withQueryString();

        return view('super-admin.shops.index', compact('shops'));
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

        return view('super-admin.shops.show', compact(
            'shop',
            'stats',
            'activeEditions',
            'availableEditions',
            'editionHistory'
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
            return back()->withErrors(['access_mode' => 'Access mode is required.']);
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

    private function dbBool(bool $value)
    {
        return $value;
    }
}
