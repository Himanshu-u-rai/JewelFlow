<?php

namespace App\Services;

use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;

class SubscriptionWebhookService
{
    private function razorpay(): Api
    {
        return new Api(
            config('services.razorpay.key_id'),
            config('services.razorpay.key_secret')
        );
    }

    /**
     * Verify the webhook signature.
     *
     * @throws \Exception
     */
    public function verifySignature(string $body, ?string $signature): void
    {
        $secret = config('services.razorpay.webhook_secret');

        if (!$secret) {
            Log::error('Razorpay webhook secret is not configured. Rejecting webhook request.');
            throw new \RuntimeException('Webhook secret not configured.');
        }

        $this->razorpay()->utility->verifyWebhookSignature($body, $signature ?? '', $secret);
    }

    /**
     * Handle the payment.captured webhook event.
     */
    public function handlePaymentCaptured(array $payload): void
    {
        $paymentId = $payload['payload']['payment']['entity']['id'] ?? null;
        if (!$paymentId) {
            return;
        }

        // Idempotent: only update if still in trial state
        $sub = ShopSubscription::where('razorpay_payment_id', $paymentId)
            ->where('status', 'trial')
            ->first();

        if ($sub) {
            $sub->update(['status' => 'active']);
            Log::info('Webhook: payment.captured — trial upgraded to active', [
                'payment_id' => $paymentId,
                'subscription_id' => $sub->id,
            ]);
        } else {
            Log::info('Webhook: payment.captured — no trial subscription to upgrade (already processed or not found)', [
                'payment_id' => $paymentId,
            ]);
        }
    }

    /**
     * Handle the payment.failed webhook event.
     */
    public function handlePaymentFailed(array $payload): void
    {
        $paymentEntity = $payload['payload']['payment']['entity'] ?? [];
        $orderId = $paymentEntity['order_id'] ?? null;
        $errorDesc = $paymentEntity['error_description'] ?? 'Unknown';

        Log::warning('Webhook: payment.failed', [
            'order_id' => $orderId,
            'error' => $errorDesc,
        ]);

        if ($orderId) {
            SubscriptionEvent::create([
                'shop_subscription_id' => null,
                'shop_id' => null,
                'admin_id' => null,
                'event_type' => 'payment.failed',
                'before' => null,
                'after' => ['order_id' => $orderId, 'error' => $errorDesc],
                'reason' => 'Razorpay webhook: payment failed — ' . $errorDesc,
            ]);
        }
    }

    /**
     * Handle the refund.created webhook event.
     */
    public function handleRefundCreated(array $payload): void
    {
        $refundEntity = $payload['payload']['refund']['entity'] ?? [];
        $paymentId = $refundEntity['payment_id'] ?? null;

        if (!$paymentId) {
            return;
        }

        // Idempotent: only cancel if not already cancelled
        $subscription = ShopSubscription::where('razorpay_payment_id', $paymentId)
            ->where('status', '!=', 'cancelled')
            ->first();

        if ($subscription) {
            $before = $subscription->toArray();
            $subscription->update(['status' => 'cancelled']);

            SubscriptionEvent::create([
                'shop_subscription_id' => $subscription->id,
                'shop_id' => $subscription->shop_id,
                'admin_id' => null,
                'event_type' => 'subscription.refunded',
                'before' => $before,
                'after' => $subscription->fresh()->toArray(),
                'reason' => 'Razorpay refund: ' . ($refundEntity['id'] ?? ''),
            ]);

            Log::info('Webhook: refund processed, subscription cancelled', [
                'payment_id' => $paymentId,
                'subscription_id' => $subscription->id,
            ]);
        } else {
            Log::info('Webhook: refund.created — subscription already cancelled or not found', [
                'payment_id' => $paymentId,
            ]);
        }
    }
}
