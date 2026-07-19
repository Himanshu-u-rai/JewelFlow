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

        DB::transaction(function () use ($validated, $shop, $admin, $before, $plan, $request, $startsAt, $endsAt, $graceEndsAt, &$invoiceId, &$deduped) {
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
