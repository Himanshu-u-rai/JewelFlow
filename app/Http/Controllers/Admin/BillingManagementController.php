<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPlatformInvoiceEmail;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Services\PlatformAuditService;
use App\Services\PlatformInvoiceService;
use App\Support\SubscriptionTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BillingManagementController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function updateShopSubscription(Request $request, Shop $shop): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id'       => ['required', 'integer', 'exists:plans,id'],
            'status'        => ['required', 'in:trial,active,grace,read_only,suspended,cancelled,expired'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly'],
            'starts_at'     => ['required', 'date'],
            'ends_at'       => ['required', 'date'],
            'price_paid'    => ['nullable', 'numeric', 'min:0.01', 'max:9999999.99'],
            'notes'         => ['nullable', 'string', 'max:1000'],
            'reason'        => ['nullable', 'string', 'max:500'],
        ]);

        // ── Guided manual extension ────────────────────────────────────────
        // The operator's exact From/To are authoritative and are NEVER recomputed
        // server-side (the form suggests safe dates but never prefills the stale
        // dates of an expired row — the JF-0001 reversion trap). The server derives
        // only the grace window and enforces invariants BEFORE persisting, so a bad
        // term creates no row / invoice / email.
        $plan     = Plan::query()->findOrFail((int) $validated['plan_id']);
        $startsAt = Carbon::parse($validated['starts_at'])->startOfDay();
        $endsAt   = Carbon::parse($validated['ends_at'])->startOfDay();
        $cycle    = $validated['billing_cycle'] ?? null;
        $now      = Carbon::now()->startOfDay(); // Asia/Kolkata business date

        $graceEndsAt = SubscriptionTerm::graceEndsAtFor($endsAt, $plan);

        $entitling = in_array($validated['status'], ['trial', 'active', 'grace'], true);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            return back()->withErrors(['ends_at' => 'To date must be after the From date.'])->withInput();
        }
        if ($entitling) {
            if (! $cycle) {
                return back()->withErrors(['billing_cycle' => 'Choose Monthly or Yearly for an active/trial/grace term.'])->withInput();
            }
            // Inclusive To: a term whose To is TODAY is still active today. Only a To
            // strictly before today's business date has already ended.
            if ($endsAt->lessThan($now)) {
                return back()->withErrors(['ends_at' => "Cannot set this subscription {$validated['status']} with a term that already ended ({$endsAt->toDateString()}). Choose a To date of today or later, or use status read_only / expired."])->withInput();
            }
            if ($startsAt->greaterThan($now)) {
                return back()->withErrors(['starts_at' => "A future-dated {$validated['status']} term needs a scheduled/pending state, which does not exist yet. Set From to today or earlier."])->withInput();
            }
        }

        $admin = auth('platform_admin')->user();
        $beforeSubscription = ShopSubscription::query()
            ->where('shop_id', $shop->id)
            ->latest('id')
            ->first();
        $before = $beforeSubscription?->toArray();

        // Audit integrity: dates that diverge from the safe suggestion require an
        // explicit override reason (backdating, gap/overlap, off-cycle duration).
        $suggestion = SubscriptionTerm::suggest($beforeSubscription, $cycle, $now);
        $differsFromSuggestion = $startsAt->toDateString() !== $suggestion['from']->toDateString()
            || ($suggestion['to'] && $endsAt->toDateString() !== $suggestion['to']->toDateString());
        if ($differsFromSuggestion && blank($validated['reason'] ?? null)) {
            return back()->withErrors(['reason' => 'These dates differ from the suggested term — enter an override reason to proceed.'])->withInput();
        }

        $invoiceId = null;
        $deduped   = false;

        DB::transaction(function () use ($validated, $shop, $admin, $before, $plan, $request, $startsAt, $endsAt, $graceEndsAt, $entitling, &$invoiceId, &$deduped) {
            $newEdition = $plan->grantsEdition();

            // Concurrency-safe idempotency. Serialize writes for THIS shop+edition
            // with a transaction-scoped Postgres advisory lock (auto-released on
            // commit/rollback). Two racing submits cannot both pass the check and
            // create two authoritative rows — the second blocks here, then re-reads
            // the committed row under the lock and hits the no-op branch below.
            // Retail and Dhiran hash to different keys and never block each other.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
                'shop_sub:' . $shop->id . ':' . ($newEdition ?? 'none'),
            ]);

            // Row-lock the shop and re-read it, because the access decision at the
            // bottom of this transaction depends on its administrative fields. The
            // $shop we were handed came from route-model binding, resolved when the
            // request was matched — before this transaction, and long after the
            // operator loaded the form. An administrator imposing a hold in that
            // window would be invisible to a stale copy, and the branch below would
            // clear a restriction it never saw. The advisory lock above only
            // serialises this shop+edition; a hold can arrive from an entirely
            // different controller, so the shop row itself has to be locked.
            // Reassignment is closure-local and deliberate: every use of $shop from
            // here down is the locked, authoritative row.
            $shop = Shop::query()->whereKey($shop->id)->lockForUpdate()->firstOrFail();

            // Re-read the latest SAME-edition row under the lock and repeat the
            // idempotency comparison. An identical resubmit is a no-op: no new
            // subscription, invoice, email, activation or supersession event.
            $lockedLatest = ShopSubscription::query()
                ->where('shop_id', $shop->id)
                ->orderByDesc('id')
                ->get()
                ->first(function (ShopSubscription $s) use ($newEdition, $plan) {
                    return $newEdition !== null
                        ? $s->plan?->grantsEdition() === $newEdition
                        : (int) $s->plan_id === (int) $plan->id;
                });

            if ($lockedLatest
                && (int) $lockedLatest->plan_id === (int) $validated['plan_id']
                && $lockedLatest->status === $validated['status']
                && ($lockedLatest->billing_cycle ?? null) === ($validated['billing_cycle'] ?? null)
                && optional($lockedLatest->starts_at)->toDateString() === $startsAt->toDateString()
                && optional($lockedLatest->ends_at)->toDateString() === $endsAt->toDateString()
                && (float) ($lockedLatest->price_paid ?? 0) === (float) ($validated['price_paid'] ?? 0)
            ) {
                $deduped = true;

                return;
            }

            $subscription = ShopSubscription::query()->create([
                'shop_id'            => $shop->id,
                'plan_id'            => (int) $validated['plan_id'],
                'status'             => $validated['status'],
                'billing_cycle'      => $validated['billing_cycle'] ?? null,
                'starts_at'          => $startsAt,
                'ends_at'            => $endsAt,
                'grace_ends_at'      => $graceEndsAt,
                'cancelled_at'       => $validated['status'] === 'cancelled' ? now() : null,
                'updated_by_admin_id' => $admin->id,
                'price_paid'         => isset($validated['price_paid']) ? (float) $validated['price_paid'] : null,
                'notes'              => $validated['notes'] ?? null,
            ]);

            // Prevent competing active subscriptions: retire any prior entitling row
            // for the SAME product on this shop. Scoped by edition so a Retail edit
            // never disturbs a live Dhiran subscription on the same owner.
            ShopSubscription::query()
                ->where('shop_id', $shop->id)
                ->where('id', '!=', $subscription->id)
                ->whereIn('status', ['trial', 'active', 'grace'])
                ->get()
                ->each(function (ShopSubscription $prior) use ($newEdition, $plan, $shop, $admin, $subscription, $validated) {
                    $samePlan = $newEdition !== null
                        ? $prior->plan?->grantsEdition() === $newEdition
                        : $prior->plan_id === $plan->id;
                    if ($samePlan) {
                        $priorBefore = $prior->toArray();
                        $prior->forceFill([
                            'status'       => 'cancelled',
                            'cancelled_at' => now(),
                        ])->save();

                        // Durable supersession trail: records WHICH old row was
                        // retired by WHICH new row, who did it, and why.
                        SubscriptionEvent::query()->create([
                            'shop_subscription_id' => $prior->id,
                            'shop_id'   => $shop->id,
                            'admin_id'  => $admin->id,
                            'event_type' => 'subscription.superseded',
                            'before'    => $priorBefore,
                            'after'     => $prior->toArray(),
                            'reason'    => "Superseded by subscription #{$subscription->id}"
                                . (blank($validated['reason'] ?? null) ? '' : " — {$validated['reason']}"),
                        ]);
                    }
                });

            // Generate invoice when admin records an actual payment (price_paid > 0)
            $pricePaid = isset($validated['price_paid']) ? (float) $validated['price_paid'] : 0;
            if ($pricePaid > 0) {
                $invoice   = app(PlatformInvoiceService::class)->issueForSubscription(
                    $subscription,
                    $admin->id,
                    'manual',
                    $validated['notes'] ?? null,
                );
                $invoiceId = $invoice->id;
            }

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

            // TWO AXES, never conflated:
            //   ENTITLEMENT   trial | active | grace | expired | cancelled
            //   ADMIN ACCESS  active | read_only | suspended
            //
            // Only the operator explicitly picking `read_only` or `suspended` is an
            // ADMINISTRATIVE act, and only that stamps suspended_by. An entitlement
            // change must never become an administrative hold: suspended_by is the
            // proof-positive discriminator, and once stamped it blocks every purchase
            // entry point (ShopSubscription::blocksNewPaidTerm) and freezes the
            // reconciler — so an ordinary expiry recorded here used to leave the owner
            // permanently unable to renew.
            if (in_array($subscription->status, ['read_only', 'suspended'], true)) {
                $shop->update([
                    'access_mode' => $subscription->status,
                    'is_active' => $this->dbBool(false),
                    'deactivated_at' => now(),
                    'suspended_at' => $shop->suspended_at ?: now(),
                    'suspended_by' => $admin->id,
                    'suspension_reason' => $validated['reason'] ?? ($subscription->status === 'read_only'
                        ? 'Read-only by platform administrator'
                        : 'Suspended by platform administrator'),
                ]);
            } elseif ($entitling) {
                // trial / active / grace all ENTITLE the shop. `grace` previously fell
                // into the read-only branch above, contradicting this controller's own
                // $entitling axis (declared before the transaction) and locking a shop
                // that is still inside its paid grace window — with a suspended_by
                // stamp that left it no self-service way out.
                //
                // But entitlement restores access only when the LACK of entitlement is
                // what removed it. This branch used to reactivate unconditionally, so
                // recording a term — a promotional win-back trial, a manually keyed
                // renewal, a grace extension — silently lifted whatever administrative
                // hold the shop was under: compliance review, abuse, closure. The
                // operator filling in a billing form has not adjudicated that case and
                // is not being asked to. Granting entitlement is not an access ruling.
                //
                // The test is POSITIVE PROOF that the restriction is subscription-
                // managed — not merely the absence of proof that it is administrative.
                // `! suspensionIsAdministrative()` would be the latter, and it fails
                // OPEN on a third category that genuinely exists in production data:
                // restrictions with no attribution at all. The 2026-02-18 control-plane
                // migration backfilled access_mode='suspended' with the reason 'Legacy
                // deactivation migration' for every already-deactivated shop and never
                // stamped suspended_by; 'Legacy shop missing subscription record' has
                // the same shape. Those are neither administrative (no actor) nor
                // subscription-managed (the reason does not corroborate), so their
                // origin is UNKNOWN — and silently reopening a shop somebody
                // deliberately closed is exactly the failure this branch is being fixed
                // for. Unknown origin therefore keeps the restriction; recovering one
                // is a deliberate access decision, not a billing side effect.
                //
                // suspensionIsSubscriptionManaged() is the existing single source of
                // truth for "recoverable by paying" — it already backs the recovery
                // gates in AuthenticatedSessionController, EnsureSubscriptionIsActive
                // and EnsureAccountIsActive — and it internally gives an administrative
                // hold precedence over whatever free text an admin may have typed. Read
                // from the LOCKED row above, never the route-bound snapshot.
                $restricted = $shop->access_mode !== 'active' || ! $shop->is_active;

                if (! $restricted || $shop->suspensionIsSubscriptionManaged()) {
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
                // Administrative hold: the subscription row and its audit trail are
                // already written above, so the entitlement IS recorded. The access
                // axis is left byte-identical — mode, actor, reason, dates, all of it.
            } else {
                // expired / cancelled: a LAPSE, not a hold. Recoverable by renewal, so
                // suspended_by stays null and the reason keeps the "Subscription "
                // prefix that Shop::suspensionIsSubscriptionManaged() reads to route
                // the owner to the plan picker instead of a Contact-Support dead end.
                //
                // Gated by the SAME POSITIVE PROOF as the entitling branch above, for
                // the mirror-image reason. There the danger was clearing a hold; here
                // it is OVERWRITING one — and this write is strictly worse, because it
                // does not merely lose the administrative reason, it manufactures the
                // exact row shape the platform treats as proof of a lapse:
                // suspended_by=null plus a "Subscription " prefix. An administrative or
                // unknown-origin restriction rewritten that way is LAUNDERED into a
                // subscription-managed one, and since platform.enforce_subscriptions
                // ships FALSE, EnsureSubscriptionIsActive::restoreIfSubscriptionManaged
                // Suspension() then heals it to access_mode='active' on the shop's very
                // next page view. A compliance hold becomes full write access, with an
                // audit trail that says a lapse did it.
                //
                // The lapse is real and IS still recorded: the subscription row and its
                // audit are written above, on the entitlement axis where they belong.
                // Only the access-axis write is withheld.
                //
                // Positive proof, never `! suspensionIsAdministrative()` — that reads
                // "no admin actor" as "safe to rewrite" and fails OPEN on the
                // unattributed third category the 2026-02-18 backfill left behind
                // ('Legacy deactivation migration' / 'Legacy shop missing subscription
                // record': no actor, no corroborating reason, unknown origin). Read from
                // the LOCKED row above, never the route-bound snapshot.
                $restricted = $shop->access_mode !== 'active' || ! $shop->is_active;

                if (! $restricted || $shop->suspensionIsSubscriptionManaged()) {
                    $shop->update([
                        'access_mode' => 'suspended',
                        'is_active' => $this->dbBool(false),
                        'deactivated_at' => now(),
                        'suspended_at' => $shop->suspended_at ?: now(),
                        'suspended_by' => null,
                        'suspension_reason' => 'Subscription ' . $subscription->status
                            . (blank($validated['reason'] ?? null) ? '' : " — {$validated['reason']}"),
                        'suspended_until' => null,
                    ]);
                }
                // Administrative or unknown-origin restriction: already at least as
                // restrictive as the lapse would make it, and only an administrator may
                // decide otherwise. Every access column is left byte-identical.
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

        // Email only AFTER a successful commit — a rolled-back transaction (or a
        // deduped no-op) sends nothing.
        if (! $deduped && $invoiceId) {
            dispatch(new SendPlatformInvoiceEmail($invoiceId));
        }

        return back()->with('success', $deduped
            ? 'Subscription already up to date — no changes applied.'
            : 'Subscription updated to ' . $plan->name . '.');
    }

    private function dbBool(bool $value)
    {
        return $value;
    }
}
