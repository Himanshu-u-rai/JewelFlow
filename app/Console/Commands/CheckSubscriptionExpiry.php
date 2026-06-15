<?php

namespace App\Console\Commands;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Support\ShopEdition;
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

                // A full lapse (expired) revokes the subscription-backed edition
                // unless another active source still justifies it. grace and
                // read_only are still entitling states — editions stay. Do this
                // BEFORE deciding shop access_mode so the "other entitled product"
                // check below reflects the post-revoke state.
                if ($newStatus === 'expired') {
                    $this->revokeEditionForLapsed($subscription);
                }

                if ($subscription->shop_id) {
                    $shop = Shop::find($subscription->shop_id);

                    // Multi-product guard: a single product's lapse must NOT
                    // suspend / read-only the WHOLE shop while another product is
                    // still entitled. Only downgrade the shop when nothing else
                    // backs it.
                    if ($shopMode !== 'active' && $shop && $this->shopHasOtherEntitledEdition($shop, $subscription)) {
                        $shopMode = 'active';
                    }

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

                if ($newStatus === 'expired') {
                    $this->revokeEditionForLapsed($subscription);
                }

                if ($subscription->shop_id) {
                    $shop = Shop::find($subscription->shop_id);

                    // Multi-product guard (see block 1): keep the shop active if
                    // another product still backs an entitled edition.
                    $effectiveMode = $shopMode;
                    if ($shop && $this->shopHasOtherEntitledEdition($shop, $subscription)) {
                        $effectiveMode = 'active';
                    }

                    if ($shop && $shop->access_mode !== $effectiveMode) {
                        $shop->forceFill([
                            'access_mode' => $effectiveMode,
                            'is_active' => $effectiveMode === 'active',
                            'suspended_at' => $effectiveMode === 'active' ? null : $now,
                            'suspension_reason' => $effectiveMode === 'active'
                                ? null
                                : "Subscription grace period ended",
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

    /**
     * Whether the shop still holds an entitled edition OTHER than the one this
     * lapsing subscription backs — used to decide if a single product's lapse
     * should downgrade the whole shop's access_mode. A shop with another active
     * product (or any admin_grant / seed edition) must stay active.
     */
    private function shopHasOtherEntitledEdition(Shop $shop, ShopSubscription $lapsing): bool
    {
        $lapsingEdition = $lapsing->plan?->grantsEdition();

        $activeEditions = ShopEditionAssignment::query()
            ->where('shop_id', $shop->id)
            ->whereNull('deactivated_at')
            ->get();

        foreach ($activeEditions as $row) {
            if ($row->edition === $lapsingEdition) {
                continue; // the product that just lapsed — ignore it
            }

            // admin_grant / seed editions are always entitled.
            if (in_array($row->source, [
                ShopEditionAssignment::SOURCE_ADMIN_GRANT,
                ShopEditionAssignment::SOURCE_SEED,
            ], true)) {
                return true;
            }

            // subscription-backed: entitled if another writable subscription
            // still grants this edition.
            if (ShopEdition::hasOtherActiveSource($shop, $row->edition, $lapsing->id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Revoke the edition a fully-lapsed (expired) subscription was backing —
     * but only when no other active source (another paid subscription, or an
     * admin_grant / seed) still justifies it. Failures are logged, never fatal.
     */
    private function revokeEditionForLapsed(ShopSubscription $subscription): void
    {
        if (! $subscription->shop_id) {
            return;
        }

        $edition = $subscription->plan?->grantsEdition();
        if (! $edition) {
            return;
        }

        try {
            $shop = Shop::find($subscription->shop_id);
            if (! $shop) {
                return;
            }

            ShopEdition::revokeFromLapsedSubscription(
                $shop,
                $edition,
                $subscription->id,
                'Subscription expired — service removed.'
            );
        } catch (\Throwable $e) {
            \Log::error('subscription:check-expiry edition revoke failed', [
                'subscription_id' => $subscription->id,
                'edition' => $edition,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
