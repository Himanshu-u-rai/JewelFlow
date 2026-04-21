<?php

namespace App\Console\Commands;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckSubscriptionExpiry extends Command
{
    protected $signature = 'subscription:check-expiry';
    protected $description = 'Check for expired subscriptions and transition shops to read-only/suspended mode';

    public function handle(): int
    {
        $now = Carbon::now();

        // 1) Find subscriptions that have expired (ends_at < now, status still active/trial)
        $expired = ShopSubscription::whereIn('status', ['active', 'trial'])
            ->where('ends_at', '<', $now)
            ->with('plan')
            ->get();

        $transitioned = 0;

        foreach ($expired as $subscription) {
            try {
                $plan = $subscription->plan;
                $graceEndsAt = $subscription->grace_ends_at;

                // Determine new status
                if ($graceEndsAt && $now->lte($graceEndsAt)) {
                    $newStatus = 'grace';
                    $shopMode = 'active';
                } elseif ($plan && $plan->downgrade_to_read_only_on_due) {
                    $newStatus = 'read_only';
                    $shopMode = 'read_only';
                } else {
                    $newStatus = 'expired';
                    $shopMode = 'suspended';
                }

                if ($subscription->status === $newStatus) {
                    continue;
                }

                $before = $subscription->toArray();
                $subscription->update(['status' => $newStatus]);

                SubscriptionEvent::create([
                    'shop_subscription_id' => $subscription->id,
                    'shop_id' => $subscription->shop_id,
                    'admin_id' => null,
                    'event_type' => 'subscription.auto_expired',
                    'before' => $before,
                    'after' => $subscription->fresh()->toArray(),
                    'reason' => "Auto-transitioned to {$newStatus} by scheduler",
                ]);

                if ($subscription->shop_id) {
                    $shop = Shop::find($subscription->shop_id);
                    if ($shop && $shop->access_mode !== $shopMode) {
                        $shop->forceFill([
                            'access_mode' => $shopMode,
                            'is_active' => $shopMode === 'active',
                            'suspended_at' => $shopMode === 'suspended' ? $now : null,
                            'suspension_reason' => $shopMode !== 'active'
                                ? "Subscription {$newStatus}"
                                : null,
                        ])->save();
                    }
                }

                $transitioned++;
            } catch (\Throwable $e) {
                $this->error("Subscription #{$subscription->id} (shop #{$subscription->shop_id}): {$e->getMessage()}");
                \Log::error("subscription:check-expiry failed", ['subscription_id' => $subscription->id, 'exception' => $e]);
            }
        }

        // 2) Find grace-period subscriptions that have passed grace_ends_at
        $graceExpired = ShopSubscription::where('status', 'grace')
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<', $now)
            ->with('plan')
            ->get();

        foreach ($graceExpired as $subscription) {
            try {
                $plan = $subscription->plan;
                $newStatus = ($plan && $plan->downgrade_to_read_only_on_due) ? 'read_only' : 'expired';
                $shopMode = $newStatus === 'read_only' ? 'read_only' : 'suspended';

                $before = $subscription->toArray();
                $subscription->update(['status' => $newStatus]);

                SubscriptionEvent::create([
                    'shop_subscription_id' => $subscription->id,
                    'shop_id' => $subscription->shop_id,
                    'admin_id' => null,
                    'event_type' => 'subscription.grace_expired',
                    'before' => $before,
                    'after' => $subscription->fresh()->toArray(),
                    'reason' => "Grace period ended, transitioned to {$newStatus}",
                ]);

                if ($subscription->shop_id) {
                    $shop = Shop::find($subscription->shop_id);
                    if ($shop) {
                        $shop->forceFill([
                            'access_mode' => $shopMode,
                            'is_active' => false,
                            'suspended_at' => $now,
                            'suspension_reason' => "Subscription grace period ended",
                        ])->save();
                    }
                }

                $transitioned++;
            } catch (\Throwable $e) {
                $this->error("Grace subscription #{$subscription->id} (shop #{$subscription->shop_id}): {$e->getMessage()}");
                \Log::error("subscription:check-expiry grace failed", ['subscription_id' => $subscription->id, 'exception' => $e]);
            }
        }

        $this->info("Processed {$expired->count()} expired + {$graceExpired->count()} grace-expired subscriptions. Transitioned: {$transitioned}");

        return self::SUCCESS;
    }
}
