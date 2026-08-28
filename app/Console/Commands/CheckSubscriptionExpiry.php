<?php

namespace App\Console\Commands;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Support\ShopEdition;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CheckSubscriptionExpiry extends Command
{
    protected $signature = 'subscription:check-expiry';
    protected $description = 'Check for expired subscriptions and suspend the shops whose term has fully lapsed';

    public function handle(): int
    {
        $now = Carbon::now();
        // Compare CALENDAR dates in the business timezone (Asia/Kolkata). ends_at
        // is a date-cast (midnight); comparing it against an afternoon now() would
        // expire a subscription at dawn on its own inclusive To day. To is inclusive:
        // active through To, grace from To+1, suspended after grace.
        $today = $now->copy()->startOfDay();

        // 1) Find subscriptions whose term has fully ended (To strictly before today).
        $expired = ShopSubscription::whereIn('status', ['active', 'trial'])
            ->where('ends_at', '<', $today)
            ->with('plan')
            ->get();

        $transitioned = 0;

        foreach ($expired as $subscription) {
            try {
                $graceEndsAt = $subscription->grace_ends_at;

                // Determine new status. Compare CALENDAR dates: the final grace day
                // (businessDate == grace_ends_at) is still within grace; suspension
                // begins the following business day. Using afternoon $now here would
                // drop the last grace day (midnight ends_at < afternoon now).
                //
                // Grace is a deliberate, plan-configured entitlement and grants FULL
                // ERP access. Once it is over the term has simply lapsed: `expired`
                // + a suspended shop, which is the RECOVERABLE state (owner → plan
                // picker, staff → logout message).
                //
                // The plan's downgrade_to_read_only_on_due column is deliberately
                // NOT read here. `read_only` is reserved exclusively for a
                // JewelFlows administrator hold (stamped with suspended_by); a
                // subscription lapse must never mint or preserve one, otherwise a
                // non-paying shop keeps a full read-only ERP and an admin hold
                // becomes indistinguishable from an unpaid bill.
                if ($graceEndsAt && $today->lte(Carbon::parse($graceEndsAt)->startOfDay())) {
                    $newStatus = 'grace';
                    $shopMode = 'active';
                } else {
                    $newStatus = 'expired';
                    $shopMode = 'suspended';
                }

                if ($subscription->status === $newStatus) {
                    continue;
                }

                // Superseded-row guard: if a NEWER subscription row for this shop
                // is already in a live state, this older row lapsing is pure
                // bookkeeping. Transition its status, but never revoke the edition
                // or downgrade the shop — the newer row (e.g. a paid term bought
                // early during a trial, or an ordinary renewal) covers it. This is
                // what makes early trial→paid upgrade seamless: when the trial row
                // expires, the already-active paid row keeps the shop running.
                $superseded = $this->hasNewerLiveSubscription($subscription);

                $before = $subscription->toArray();
                $subscription->update(['status' => $newStatus]);

                SubscriptionEvent::create([
                    'shop_subscription_id' => $subscription->id,
                    'shop_id' => $subscription->shop_id,
                    'admin_id' => null,
                    'event_type' => 'subscription.auto_expired',
                    'before' => $before,
                    'after' => $subscription->fresh()->toArray(),
                    'reason' => $superseded
                        ? "Auto-transitioned to {$newStatus} by scheduler (superseded by a newer live subscription — shop unaffected)"
                        : "Auto-transitioned to {$newStatus} by scheduler",
                ]);

                // A full lapse (expired) revokes the subscription-backed edition
                // unless another active source still justifies it. grace is still
                // an entitling state — editions stay. Do this
                // BEFORE deciding shop access_mode so the "other entitled product"
                // check below reflects the post-revoke state.
                // Skip entirely when superseded: a newer live row of the same
                // product is still granting the edition.
                if ($newStatus === 'expired' && ! $superseded) {
                    $this->revokeEditionForLapsed($subscription);
                }

                if ($subscription->shop_id && ! $superseded) {
                    $this->applyShopModeUnderLock(
                        $subscription->shop_id,
                        $subscription,
                        $shopMode,
                        "Subscription {$newStatus}",
                        $now
                    );
                }

                $transitioned++;
            } catch (\Throwable $e) {
                $this->error("Subscription #{$subscription->id} (shop #{$subscription->shop_id}): {$e->getMessage()}");
                \Log::error("subscription:check-expiry failed", ['subscription_id' => $subscription->id, 'exception' => $e]);
            }
        }

        // 2) Find grace-period subscriptions that have passed grace_ends_at.
        // Calendar compare: grace is inclusive of grace_ends_at, so only rows whose
        // grace end is strictly BEFORE today have truly lapsed. Comparing against
        // afternoon $now would suspend a shop on its own final grace day.
        $graceExpired = ShopSubscription::where('status', 'grace')
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<', $today)
            ->with('plan')
            ->get();

        foreach ($graceExpired as $subscription) {
            try {
                // Grace is over, so the term has fully lapsed. Same rule as above:
                // never read downgrade_to_read_only_on_due, never mint read_only.
                $newStatus = 'expired';
                $shopMode = 'suspended';

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

                $this->revokeEditionForLapsed($subscription);

                if ($subscription->shop_id) {
                    $this->applyShopModeUnderLock(
                        $subscription->shop_id,
                        $subscription,
                        $shopMode,
                        'Subscription grace period ended',
                        $now
                    );
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
     * Apply a shop access_mode downgrade for a lapsed subscription — but ONLY
     * when subscription enforcement is on, and ONLY under a row lock that is
     * re-evaluated after acquisition.
     *
     * Enforcement OFF (the default): a subscription lapse must NEVER lock ERP
     * access. The subscription status has already been transitioned above (pure
     * tracking); the shop row is left completely untouched.
     *
     * Enforcement ON: lock the shop row so this serialises against
     * SubscriptionPaymentService (which locks the same row inside its payment
     * transaction). After acquiring the lock we RE-CHECK, because a concurrent
     * payment may have raced us:
     *   - a newer live subscription now covers the shop  → bookkeeping only, skip;
     *   - the shop is under a human admin suspension      → never override it;
     *   - another product still entitles the shop         → keep it active.
     * This is what proves a freshly-paid shop can never be re-suspended by the
     * expiry job.
     */
    private function applyShopModeUnderLock(
        int $shopId,
        ShopSubscription $lapsing,
        string $shopMode,
        string $reason,
        Carbon $now
    ): void {
        if (! config('platform.enforce_subscriptions', false)) {
            return;
        }

        DB::transaction(function () use ($shopId, $lapsing, $shopMode, $reason, $now) {
            $shop = Shop::whereKey($shopId)->lockForUpdate()->first();
            if (! $shop) {
                return;
            }

            // Raced by a payment that created a newer live term → do not downgrade.
            if ($this->hasNewerLiveSubscription($lapsing)) {
                return;
            }

            // Never override a human platform-admin suspension.
            if ($shop->suspensionIsAdministrative()) {
                return;
            }

            // Multi-product guard: keep the shop active if another product still
            // backs an entitled edition.
            $effectiveMode = $shopMode;
            if ($shopMode !== 'active' && $this->shopHasOtherEntitledEdition($shop, $lapsing)) {
                $effectiveMode = 'active';
            }

            if ($shop->access_mode !== $effectiveMode) {
                $shop->forceFill([
                    'access_mode' => $effectiveMode,
                    'is_active' => $effectiveMode === 'active',
                    'suspended_at' => $effectiveMode === 'suspended' ? $now : null,
                    'suspension_reason' => $effectiveMode !== 'active' ? $reason : null,
                ])->save();
            }
        });
    }

    /**
     * Whether a NEWER subscription row for the same shop is in a live state
     * (trial / active / grace). When true, the lapsing of an older row is
     * bookkeeping only — the newer row already covers the shop, so the shop
     * must not be downgraded and the edition must not be revoked.
     *
     * `read_only` is NOT a live state. It is either a legacy row minted by the
     * old expiry fork (a lapse, not coverage) or an administrator hold — neither
     * entitles the shop, so neither may shield another row from lapsing.
     *
     * This is what makes an early trial→paid upgrade seamless: the paid row is
     * created with a higher id while the trial is still live, so when the trial
     * row later expires this returns true and the shop keeps running.
     */
    private function hasNewerLiveSubscription(ShopSubscription $subscription): bool
    {
        if (! $subscription->shop_id) {
            return false;
        }

        return ShopSubscription::where('shop_id', $subscription->shop_id)
            ->where('id', '>', $subscription->id)
            ->whereIn('status', ['trial', 'active', 'grace'])
            ->exists();
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
