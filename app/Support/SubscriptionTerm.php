<?php

namespace App\Support;

use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for how long a paid subscription term runs and when
 * its grace window ends.
 *
 * Kept as pure, side-effect-free calendar arithmetic so EVERY creation path —
 * self-service Razorpay (SubscriptionPaymentService), the yearly-trial repair
 * command, and Super Admin manual billing edits (BillingManagementController) —
 * computes identical terms. Before this helper the admin path did its own
 * arithmetic (in practice: none — it trusted a stale, form-prefilled ends_at),
 * which silently expired paid yearly terms on arrival.
 *
 * NEVER read trial_days here: a paid term's length is a pure function of its
 * billing cycle. addYear/addMonth are calendar-safe (month lengths, leap years).
 */
class SubscriptionTerm
{
    /**
     * Inclusive To of a paid term that begins at $startsAt for the given billing
     * cycle. Anything other than 'yearly' is a monthly term.
     *
     * From/To are INCLUSIVE business dates, so a one-cycle term runs from $startsAt
     * through the day BEFORE the next-cycle anniversary: To = From + 1 cycle
     * (no overflow) − 1 day. The next term then starts at To + 1 day — no gap,
     * no overlap. No-overflow keeps month/leap arithmetic calendar-safe:
     *   19 Jul 2026 monthly → 18 Aug 2026;  yearly → 18 Jul 2027
     *   31 Jan 2026 monthly → 27 Feb 2026;  31 Jan 2028 (leap) → 28 Feb 2028
     *   31 Mar 2026 monthly → 29 Apr 2026
     *   29 Feb 2028 yearly  → 27 Feb 2029
     */
    public static function endsAtFor(?string $billingCycle, Carbon $startsAt): Carbon
    {
        return $billingCycle === 'yearly'
            ? $startsAt->copy()->addYearNoOverflow()->subDay()
            : $startsAt->copy()->addMonthNoOverflow()->subDay();
    }

    /**
     * End of the grace window: term end + the plan's grace days, falling back to
     * the platform default when the plan has none.
     */
    public static function graceEndsAtFor(Carbon $endsAt, ?Plan $plan): Carbon
    {
        $graceDays = $plan?->grace_days ?? config('business.subscription_grace_days');

        return $endsAt->copy()->addDays($graceDays);
    }

    /**
     * Safe suggested From/To for a manual Super Admin term, in Asia/Kolkata
     * business dates. These are SUGGESTIONS ONLY — the operator's submitted dates
     * win. The server never silently prefills the stale dates of an expired row
     * (that was the JF-0001 reversion trap).
     *
     * Policy:
     *   - Previous term still live (active/trial/grace and grace not yet passed)
     *     → continue from the calendar day AFTER its ends_at. To is inclusive, so
     *       the prior term covers through ends_at; starting the next term on
     *       ends_at + 1 day gives no gap and no overlap.
     *   - Otherwise (no previous, or fully lapsed) → start today.
     *   - To = From + billing cycle (null when no cycle chosen yet).
     *
     * (The automatic Razorpay renewal in SubscriptionPaymentService anchors the
     * next term at ends_at itself; that arithmetic is intentionally left as-is.
     * This suggestion is a manual-flow default only — the operator's dates win.)
     *
     * @return array{from: Carbon, to: ?Carbon}
     */
    public static function suggest(?ShopSubscription $previous, ?string $billingCycle, ?Carbon $now = null): array
    {
        $now  = ($now ?? Carbon::now())->copy()->startOfDay();
        $from = $now;

        if ($previous && $previous->ends_at) {
            $graceEnd = $previous->grace_ends_at ?? $previous->ends_at;
            $stillLive = in_array($previous->status, ['active', 'trial', 'grace'], true)
                && Carbon::parse($graceEnd)->startOfDay()->greaterThanOrEqualTo($now);

            if ($stillLive) {
                $from = Carbon::parse($previous->ends_at)->startOfDay()->addDay();
            }
        }

        return [
            'from' => $from,
            'to'   => $billingCycle ? self::endsAtFor($billingCycle, $from) : null,
        ];
    }
}
