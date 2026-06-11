<?php

namespace App\Observers;

use App\Models\QuickBill;
use App\Services\Mobile\SaleNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fires an owner sale-notification when a quick bill becomes issued.
 *
 * Issued-only + DB::afterCommit (rolled-back bill never notifies) + the
 * service's (invoice_id, invoice_type) dedupe guard = fires exactly once,
 * including when a draft is later updated to issued.
 */
class QuickBillNotificationObserver
{
    public function created(QuickBill $bill): void
    {
        $this->maybeNotify($bill);
    }

    public function updated(QuickBill $bill): void
    {
        if ($bill->wasChanged('status')) {
            $this->maybeNotify($bill);
        }
    }

    private function maybeNotify(QuickBill $bill): void
    {
        if ($bill->status !== QuickBill::STATUS_ISSUED) {
            return; // skip draft / void
        }

        DB::afterCommit(function () use ($bill) {
            try {
                $actorName = optional($bill->createdBy)->name
                    ?? auth()->user()?->name
                    ?? 'Staff';

                app(SaleNotificationService::class)->dispatchForSale(
                    shopId:       (int) $bill->shop_id,
                    counterType:  'quick_bill',
                    invoiceType:  'quick_bill',
                    invoiceId:    (int) $bill->id,
                    amount:       (float) $bill->total_amount,
                    actorName:    $actorName,
                    customerName: $bill->customer_name, // already a column; null = walk-in
                );
            } catch (\Throwable $e) {
                Log::warning('Sale notification (quick bill) failed: ' . $e->getMessage());
            }
        });
    }
}
