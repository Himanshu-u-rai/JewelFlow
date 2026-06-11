<?php

namespace App\Services\Mobile;

use App\Models\ShopNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Notifies a shop's owners when a sale is made (POS or Quick Bill).
 *
 * Contract (see spec):
 *  - Call ONLY from the real sale-creation path, AFTER the transaction commits
 *    (a push is irreversible; a rolled-back sale must not notify; an idempotent
 *    replay must not double-notify — which is why the emit lives in the service,
 *    not the controller).
 *  - Recipients = active users of the shop whose role is 'owner'. The actor is
 *    intentionally the operator even when the operator is an owner.
 *  - Writes one feed row per owner and queues one push per owner (per-owner
 *    data: notification_id + user_id for the device's cross-operator guard).
 */
class SaleNotificationService
{
    public function __construct(private PushNotificationService $push) {}

    public function dispatchForSale(
        int $shopId,
        string $counterType,   // 'pos' | 'quick_bill'
        string $invoiceType,   // 'invoice' | 'quick_bill'
        int $invoiceId,
        float $amount,
        string $actorName,
        ?string $customerName,
    ): void {
        // Owners of this shop, resolved explicitly by shop_id (works even with no
        // tenant context bound). whereRaw IS TRUE avoids the pgsql boolean-bind
        // pitfall the rest of the codebase guards against.
        $owners = User::query()
            ->where('shop_id', $shopId)
            ->whereRaw('users.is_active IS TRUE')
            ->whereHas('role', fn ($q) => $q->where('name', 'owner'))
            ->get();

        foreach ($owners as $owner) {
            $note = ShopNotification::create([
                'shop_id'           => $shopId,
                'recipient_user_id' => $owner->id,
                'type'              => 'sale',
                'counter_type'      => $counterType,
                'actor_name'        => $actorName,
                'amount'            => $amount,
                'customer_name'     => $customerName,
                'invoice_id'        => $invoiceId,
                'invoice_type'      => $invoiceType,
            ]);

            $this->sendPush((int) $owner->id, $note);
        }
    }

    private function sendPush(int $ownerId, ShopNotification $note): void
    {
        $counterLabel = $note->counter_type === 'pos' ? 'POS' : 'Quick Bill';
        $amount = '₹' . number_format((float) $note->amount, 0);
        $body = $note->customer_name
            ? "{$note->actor_name} sold {$amount} ({$counterLabel}) to {$note->customer_name}"
            : "{$note->actor_name} sold {$amount} ({$counterLabel})";

        // Reuse the existing Expo push platform (queued, shop-scoped). One call
        // per owner so each push carries that owner's own notification_id/user_id.
        // All ids are JSON numbers (the device cross-operator guard expects an
        // integer user_id, and the client validates invoice_id as a positive int).
        $this->push->queueToShop(
            (int) $note->shop_id,
            'New sale',
            $body,
            [
                'type'            => 'sale',
                'notification_id' => (int) $note->id,
                'counter_type'    => $note->counter_type,
                'invoice_id'      => (int) $note->invoice_id,
                'invoice_type'    => $note->invoice_type,
                'user_id'         => $ownerId,
            ],
            [$ownerId],
        );
    }
}
