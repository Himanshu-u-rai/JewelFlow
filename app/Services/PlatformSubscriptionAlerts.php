<?php

namespace App\Services;

use App\Jobs\SendOpsAlertEmail;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Composes the small, closed set of JewelFlows internal ops emails and hands
 * each to the queued, retryable SendOpsAlertEmail job (dispatched after commit).
 *
 * SCOPE — emails are sent ONLY for:
 *   • a genuinely new shop successfully created            → shopCreated()
 *   • a captured payment successfully applied              → paymentApplied()
 *   • a captured payment that could not be applied yet     → reconciliationRequired()
 *   • a captured payment that will never apply (validation)→ permanentFailure()
 *   • a previously-unresolved payment later recovered      → paymentReconciled()
 *
 * NEVER for login/logout/session/device, trial/grace expiry, subscription
 * middleware redirects, plan-page visits, ordinary CRUD or state changes.
 *
 * Each method is called at exactly one fire-site that runs once per shop /
 * per provider payment, so no dedup store is needed. Bodies carry only
 * human-readable references — NO signatures, keys, tokens or raw payloads.
 */
class PlatformSubscriptionAlerts
{
    public function shopCreated(Shop $shop, ?ShopSubscription $subscription = null): void
    {
        $lines = [
            "A new JewelFlows shop was created.",
            "Shop: {$shop->name} (#{$shop->id})",
            "Type: " . ($shop->shop_type ?? '—'),
        ];

        if ($subscription) {
            $lines[] = "Plan: " . ($subscription->plan?->name ?? '#' . $subscription->plan_id);
            $lines[] = "Status: {$subscription->status}";
            if ($subscription->status === 'trial' && $subscription->ends_at) {
                $lines[] = "Trial ends: " . $this->date($subscription->ends_at);
            }
        }

        $this->send("New shop created — {$shop->name}", $lines);
    }

    public function paymentApplied(ShopSubscription $subscription): void
    {
        $lines = [
            "A Razorpay payment was captured and applied to a subscription.",
            "Payment ref: " . ($subscription->razorpay_payment_id ?? '—'),
            "Shop: " . $this->shopLabel($subscription),
            "Plan: " . ($subscription->plan?->name ?? '#' . $subscription->plan_id),
            "Billing: " . ($subscription->billing_cycle ?? '—'),
            "Amount paid: ₹" . number_format((float) $subscription->price_paid, 2),
            "Term: " . $this->date($subscription->starts_at) . " → " . $this->date($subscription->ends_at),
            "Grace ends: " . $this->date($subscription->grace_ends_at),
        ];

        $this->send("Payment applied — " . $this->shopLabel($subscription), $lines);
    }

    public function reconciliationRequired(SubscriptionEvent $event): void
    {
        $after = $event->after ?? [];
        $lines = [
            "A captured Razorpay payment could NOT be applied yet and needs review.",
            "Payment ref: " . ($after['payment_id'] ?? '—'),
            "Order ref: " . ($after['order_id'] ?? '—'),
            "Reason: {$event->reason}",
            "This is a transient failure — automatic reconciliation will keep retrying.",
        ];

        $this->send("Payment unresolved (retrying) — " . ($after['payment_id'] ?? 'unknown'), $lines);
    }

    public function permanentFailure(SubscriptionEvent $event): void
    {
        $after = $event->after ?? [];
        $lines = [
            "A captured Razorpay payment FAILED server-side validation and will not be applied.",
            "Payment ref: " . ($after['payment_id'] ?? '—'),
            "Order ref: " . ($after['order_id'] ?? '—'),
            "Reason: {$event->reason}",
            "Manual review / refund may be required — see Unresolved Payments in the admin panel.",
        ];

        $this->send("Payment permanently unresolved — " . ($after['payment_id'] ?? 'unknown'), $lines);
    }

    public function paymentReconciled(ShopSubscription $subscription): void
    {
        $lines = [
            "A previously-unresolved Razorpay payment was successfully reconciled and applied.",
            "Payment ref: " . ($subscription->razorpay_payment_id ?? '—'),
            "Shop: " . $this->shopLabel($subscription),
            "Plan: " . ($subscription->plan?->name ?? '#' . $subscription->plan_id),
            "Amount paid: ₹" . number_format((float) $subscription->price_paid, 2),
            "Term: " . $this->date($subscription->starts_at) . " → " . $this->date($subscription->ends_at),
        ];

        $this->send("Payment reconciled — " . $this->shopLabel($subscription), $lines);
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function send(string $subject, array $lines): void
    {
        // Alerting is strictly best-effort: a mail/queue hiccup must NEVER roll
        // back the shop / payment / subscription that already committed. Under a
        // sync queue the job runs inline, so a delivery failure would surface
        // here — swallow it. Under a real queue this just enqueues.
        try {
            // afterCommit: inside an open transaction the job is only queued once
            // that transaction commits; outside one it dispatches immediately.
            SendOpsAlertEmail::dispatch($subject, implode("\n", $lines))->afterCommit();
        } catch (\Throwable $e) {
            Log::error('PlatformSubscriptionAlerts: failed to dispatch ops alert', [
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function shopLabel(ShopSubscription $subscription): string
    {
        if (! $subscription->shop_id) {
            return 'pending onboarding';
        }

        return ($subscription->shop?->name ?? 'Shop') . " (#{$subscription->shop_id})";
    }

    private function date($value): string
    {
        return $value ? Carbon::parse($value)->toDateString() : '—';
    }
}
