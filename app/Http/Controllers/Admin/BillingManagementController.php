<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingManagementController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function updateShopSubscription(Request $request, Shop $shop): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'status' => ['required', 'in:trial,active,grace,read_only,suspended,cancelled,expired'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'grace_ends_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $admin = auth('platform_admin')->user();
        $beforeSubscription = ShopSubscription::query()
            ->where('shop_id', $shop->id)
            ->latest('id')
            ->first();
        $before = $beforeSubscription?->toArray();

        DB::transaction(function () use ($validated, $shop, $admin, $beforeSubscription, $before, $request) {
            $subscription = ShopSubscription::query()->create([
                'shop_id' => $shop->id,
                'plan_id' => (int) $validated['plan_id'],
                'status' => $validated['status'],
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'] ?? null,
                'grace_ends_at' => $validated['grace_ends_at'] ?? null,
                'cancelled_at' => $validated['status'] === 'cancelled' ? now() : null,
                'updated_by_admin_id' => $admin->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            SubscriptionEvent::query()->create([
                'shop_subscription_id' => $subscription->id,
                'shop_id' => $shop->id,
                'admin_id' => $admin->id,
                'event_type' => 'subscription.changed',
                'before' => $before,
                'after' => $subscription->toArray(),
                'reason' => $validated['reason'] ?? null,
            ]);

            $shopBefore = $shop->only(['access_mode', 'is_active']);
            if (in_array($subscription->status, ['suspended', 'cancelled', 'expired'], true)) {
                $shop->update([
                    'access_mode' => 'suspended',
                    'is_active' => $this->dbBool(false),
                    'deactivated_at' => now(),
                    'suspended_at' => $shop->suspended_at ?: now(),
                    'suspended_by' => $admin->id,
                    'suspension_reason' => $validated['reason'] ?? 'Suspended by subscription status',
                ]);
            } elseif (in_array($subscription->status, ['read_only', 'grace'], true)) {
                $shop->update([
                    'access_mode' => 'read_only',
                    'is_active' => $this->dbBool(false),
                    'deactivated_at' => now(),
                    'suspended_at' => $shop->suspended_at ?: now(),
                    'suspended_by' => $admin->id,
                    'suspension_reason' => $validated['reason'] ?? 'Read-only by subscription status',
                ]);
            } else {
                $shop->update([
                    'access_mode' => 'active',
                    'is_active' => $this->dbBool(true),
                    'deactivated_at' => null,
                    'suspended_at' => null,
                    'suspended_by' => null,
                    'suspension_reason' => null,
                    'suspended_until' => null,
                ]);
            }

            $this->audit->log(
                $admin,
                'billing.subscription_changed',
                Shop::class,
                $shop->id,
                ['subscription' => $before, 'shop' => $shopBefore],
                ['subscription' => $subscription->toArray(), 'shop' => $shop->fresh()->only(['access_mode', 'is_active'])],
                $validated['reason'] ?? null,
                $request
            );
        });

        $plan = Plan::query()->find((int) $validated['plan_id']);
        return back()->with('success', 'Subscription updated to ' . ($plan?->name ?? 'plan') . '.');
    }

    private function dbBool(bool $value)
    {
        return $value;
    }
}
