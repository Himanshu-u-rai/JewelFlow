<?php

namespace App\Console\Commands;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Support\SubscriptionTerm;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-shot data repair for the yearly-trial bug.
 *
 * Paid subscriptions that were created while createSubscription() still read
 * trial_days got a shrunken 7-day window (ends_at ≈ starts_at + 7d) instead of
 * the full paid term. Those customers then aged into grace/read_only/expired.
 *
 * This command recomputes the correct full term from billing_cycle, recomputes
 * status against now() (mirroring CheckSubscriptionExpiry), and — under --commit
 * — writes the correction plus a subscription.repaired audit event, restoring
 * shop access when a shop was wrongly downgraded.
 *
 * Default is a dry-run: it prints the affected rows and writes NOTHING.
 */
class RepairTrialTermSubscriptions extends Command
{
    protected $signature = 'subscription:repair-trial-terms {--commit : actually write changes (default is a dry-run)}';

    protected $description = 'Repair paid subscriptions whose term was wrongly shrunk to a trial window (yearly-trial bug)';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $now = Carbon::now();

        $affected = $this->findAffected();

        if ($affected->isEmpty()) {
            $this->info('No affected subscriptions found. Nothing to repair.');
            return self::SUCCESS;
        }

        $rows = [];
        $repaired = 0;

        foreach ($affected as $subscription) {
            $plan = $subscription->plan;
            $startsAt = Carbon::parse($subscription->starts_at);
            $cycle = $subscription->billing_cycle === 'yearly' ? 'yearly' : 'monthly';

            $correctEndsAt = SubscriptionTerm::endsAtFor($cycle, $startsAt);
            $correctGraceEndsAt = SubscriptionTerm::graceEndsAtFor($correctEndsAt, $plan);

            // Recompute status against now() — same logic as CheckSubscriptionExpiry.
            [$correctStatus, $shopMode] = $this->resolveStatus($now, $correctEndsAt, $correctGraceEndsAt, $plan);

            $rows[] = [
                $subscription->id,
                $subscription->shop_id ?? '—',
                Carbon::parse($subscription->ends_at)->toDateString(),
                $correctEndsAt->toDateString(),
                $subscription->status,
                $correctStatus,
            ];

            if (!$commit) {
                continue;
            }

            try {
                DB::transaction(function () use (
                    $subscription, $correctEndsAt, $correctGraceEndsAt,
                    $correctStatus, $shopMode, $now
                ) {
                    $before = $subscription->toArray();

                    $subscription->update([
                        'ends_at' => $correctEndsAt,
                        'grace_ends_at' => $correctGraceEndsAt,
                        'status' => $correctStatus,
                    ]);

                    SubscriptionEvent::create([
                        'shop_subscription_id' => $subscription->id,
                        'shop_id' => $subscription->shop_id,
                        'admin_id' => null,
                        'event_type' => 'subscription.repaired',
                        'before' => $before,
                        'after' => $subscription->fresh()->toArray(),
                        'reason' => 'Yearly-trial bug correction (Phase 1 data repair)',
                    ]);

                    // If a shop was wrongly downgraded and the corrected status is
                    // active, restore full access. Mirror CheckSubscriptionExpiry's
                    // applyShopModeUnderLock(): take the row lock INSIDE this
                    // transaction — a lockForUpdate() outside one is released the
                    // instant the statement ends and guards nothing — then refuse to
                    // touch an administrative hold.
                    //
                    // A data repair owns the ENTITLEMENT axis only. Lifting a
                    // JewelFlows administrator's read-only/suspension here would
                    // silently undo a compliance or fraud hold with no admin acting,
                    // and suspended_by (the only record of who imposed it) is left
                    // strictly alone.
                    if ($subscription->shop_id && $correctStatus === 'active') {
                        $shop = Shop::whereKey($subscription->shop_id)->lockForUpdate()->first();
                        if ($shop && $shop->access_mode !== 'active' && ! $shop->suspensionIsAdministrative()) {
                            $shop->forceFill([
                                'access_mode' => 'active',
                                'is_active' => true,
                                'suspended_at' => null,
                                'suspension_reason' => null,
                            ])->save();
                        }
                    }
                });

                $repaired++;
            } catch (\Throwable $e) {
                $this->error("Subscription #{$subscription->id} (shop #{$subscription->shop_id}): {$e->getMessage()}");
                \Log::error('subscription:repair-trial-terms failed', [
                    'subscription_id' => $subscription->id,
                    'exception' => $e,
                ]);
            }
        }

        $this->table(
            ['Sub ID', 'Shop ID', 'Current ends_at', 'Corrected ends_at', 'Current status', 'Corrected status'],
            $rows
        );

        if ($commit) {
            $this->info("Repaired {$repaired} of {$affected->count()} affected subscription(s).");
        } else {
            $this->warn("DRY RUN — found {$affected->count()} affected subscription(s). Nothing was written. Re-run with --commit to apply.");
        }

        return self::SUCCESS;
    }

    /**
     * Read-only detection of paid subscriptions whose term was shrunk to a
     * trial-length window.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ShopSubscription>
     */
    private function findAffected()
    {
        return ShopSubscription::query()
            ->with('plan')
            ->whereNotNull('razorpay_payment_id')
            ->where('price_paid', '>', 0)
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->where(function ($q) {
                // Yearly subs whose term is <= starts_at + 31 days.
                $q->where(function ($yearly) {
                    $yearly->where('billing_cycle', 'yearly')
                        ->whereRaw("ends_at <= starts_at + INTERVAL '31 days'");
                })
                // Monthly subs whose term is <= starts_at + 8 days.
                ->orWhere(function ($monthly) {
                    $monthly->where('billing_cycle', 'monthly')
                        ->whereRaw("ends_at <= starts_at + INTERVAL '8 days'");
                });
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Mirror CheckSubscriptionExpiry's status resolution given a corrected term.
     *
     * A repair must never mint an administrative state. `read_only` belongs to
     * platform-admin holds only, so a term that has run past its grace resolves
     * to `expired` + `suspended` — exactly what the scheduler now writes. The
     * plan's downgrade_to_read_only_on_due column is deliberately not read.
     *
     * @return array{0: string, 1: string} [status, shopMode]
     */
    private function resolveStatus(Carbon $now, Carbon $correctEndsAt, Carbon $correctGraceEndsAt, $plan): array
    {
        if ($now->lte($correctEndsAt)) {
            return ['active', 'active'];
        }

        if ($now->lte($correctGraceEndsAt)) {
            return ['grace', 'active'];
        }

        return ['expired', 'suspended'];
    }
}
