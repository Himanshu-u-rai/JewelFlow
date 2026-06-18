<?php

namespace App\Services;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Admin-controlled free-trial length.
 *
 * The trial length lives in PlatformSetting ('subscription_trial_days') and is
 * read by PlatformSetting::trialDays() for NEW trials. This service applies a
 * length change to shops that are ALREADY on trial, on explicit admin request.
 *
 * Recompute policy (confirmed product decision):
 *  - new ends_at = trial's ORIGINAL starts_at + N days (predictable, idempotent;
 *    re-applying the same N is a no-op). Can extend or shorten.
 *  - if the computed date is before today, CLAMP to today so a shop is never
 *    yanked to read-only mid-session — the normal expiry sweep then handles it.
 *  - trials carry no grace, so grace_ends_at tracks ends_at.
 *  - scope is status = 'trial' only; active/grace/expired/cancelled untouched.
 *
 * Every changed row gets a SubscriptionEvent (forensic audit). Runs in chunks
 * inside a transaction. No schema change, no destructive operation.
 */
class SubscriptionTrialService
{
    /**
     * Recompute ends_at for every active trial to start + $days (clamped to ≥ today).
     *
     * @return array{updated:int, clamped:int, unchanged:int}
     */
    public function applyTrialLengthToActiveTrials(int $days, ?int $adminId = null): array
    {
        $days = max(1, min(365, $days));
        $today = CarbonImmutable::now()->startOfDay();

        $updated = 0;
        $clamped = 0;
        $unchanged = 0;

        DB::transaction(function () use ($days, $today, $adminId, &$updated, &$clamped, &$unchanged) {
            ShopSubscription::query()
                ->where('status', 'trial')
                ->orderBy('id')
                ->chunkById(200, function ($subscriptions) use ($days, $today, $adminId, &$updated, &$clamped, &$unchanged) {
                    foreach ($subscriptions as $sub) {
                        $startsAt = $sub->starts_at
                            ? CarbonImmutable::parse($sub->starts_at)->startOfDay()
                            : CarbonImmutable::parse($sub->created_at)->startOfDay();

                        $computed = $startsAt->addDays($days);
                        $wasClamped = $computed->lessThan($today);
                        $newEndsAt = $wasClamped ? $today : $computed;

                        $oldEndsAt = $sub->ends_at ? CarbonImmutable::parse($sub->ends_at)->startOfDay() : null;

                        // Idempotent: skip if nothing actually changes.
                        if ($oldEndsAt !== null && $oldEndsAt->equalTo($newEndsAt)) {
                            $unchanged++;
                            continue;
                        }

                        $before = [
                            'ends_at'       => optional($sub->ends_at)->toDateString(),
                            'grace_ends_at' => optional($sub->grace_ends_at)->toDateString(),
                        ];

                        $sub->ends_at = $newEndsAt->toDateString();
                        $sub->grace_ends_at = $newEndsAt->toDateString(); // trials have no grace
                        $sub->save();

                        SubscriptionEvent::create([
                            'shop_subscription_id' => $sub->id,
                            'shop_id'              => $sub->shop_id,
                            'admin_id'             => $adminId,
                            'event_type'           => 'subscription.trial_length_changed',
                            'before'               => $before,
                            'after'                => [
                                'ends_at'        => $newEndsAt->toDateString(),
                                'grace_ends_at'  => $newEndsAt->toDateString(),
                                'trial_days'     => $days,
                                'clamped_to_today' => $wasClamped,
                            ],
                            'reason'               => "Trial length set to {$days} days by admin"
                                . ($wasClamped ? ' (clamped to today — computed end was in the past)' : ''),
                        ]);

                        $updated++;
                        if ($wasClamped) {
                            $clamped++;
                        }
                    }
                });
        });

        return ['updated' => $updated, 'clamped' => $clamped, 'unchanged' => $unchanged];
    }
}
