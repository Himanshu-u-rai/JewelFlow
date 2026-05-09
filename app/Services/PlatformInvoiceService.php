<?php

namespace App\Services;

use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;

class PlatformInvoiceService
{
    /**
     * Issue a PlatformInvoice for a subscription event.
     *
     * price_paid is the total amount charged (GST-inclusive when via Razorpay).
     * We back-calculate the pre-tax amount from the configured GST rate.
     *
     * @param  string  $paymentMethod  razorpay | manual | free
     */
    public function issueForSubscription(
        ShopSubscription $subscription,
        ?int $createdByAdminId = null,
        string $paymentMethod = 'razorpay',
        ?string $notes = null,
    ): PlatformInvoice {
        $gstRate   = (float) config('business.platform_invoice_gst_rate', 18.0);
        $totalPaid = round((float) $subscription->price_paid, 2);

        if ($totalPaid <= 0) {
            throw new \InvalidArgumentException(
                "Cannot issue a platform invoice for subscription #{$subscription->id}: price_paid is null or zero."
            );
        }

        // price_paid is GST-inclusive; derive pre-tax amount
        $amountBeforeTax = round($totalPaid / (1 + $gstRate / 100), 2);
        $gstAmount       = round($totalPaid - $amountBeforeTax, 2);

        $identity = BusinessIdentifierService::nextPlatformInvoiceNumber();

        return PlatformInvoice::create([
            'shop_id'              => $subscription->shop_id,
            'shop_subscription_id' => $subscription->id,
            'plan_id'              => $subscription->plan_id,
            'invoice_number'       => $identity['number'],
            'invoice_sequence'     => $identity['sequence'],
            'billing_cycle'        => $subscription->billing_cycle ?? 'monthly',
            'billing_period_start' => $subscription->starts_at,
            'billing_period_end'   => $subscription->ends_at
                ?? (($subscription->billing_cycle ?? 'monthly') === 'yearly'
                    ? \Carbon\Carbon::parse($subscription->starts_at)->addYear()
                    : \Carbon\Carbon::parse($subscription->starts_at)->addMonth()),
            'amount_before_tax'    => $amountBeforeTax,
            'gst_rate'             => $gstRate,
            'gst_amount'           => $gstAmount,
            'total_amount'         => $totalPaid,
            'razorpay_payment_id'  => $subscription->razorpay_payment_id,
            'razorpay_order_id'    => $subscription->razorpay_order_id,
            'payment_method'       => $paymentMethod,
            'status'               => 'issued',
            'issued_at'            => now(),
            'created_by_admin_id'  => $createdByAdminId,
            'notes'                => $notes,
        ]);
    }
}
