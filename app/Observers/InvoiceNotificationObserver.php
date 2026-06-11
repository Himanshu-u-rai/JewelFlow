<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Mobile\SaleNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fires an owner sale-notification when an invoice becomes finalized.
 *
 * Dedicated to notifications only — kept separate from the (currently dormant)
 * InvoiceObserver so registering this does not also activate that observer's
 * unrelated entity-event recording.
 *
 * Finalized-only + DB::afterCommit (rolled-back sale never notifies) + the
 * service's (invoice_id, invoice_type) dedupe guard = fires exactly once.
 */
class InvoiceNotificationObserver
{
    public function created(Invoice $invoice): void
    {
        $this->maybeNotify($invoice);
    }

    public function updated(Invoice $invoice): void
    {
        if ($invoice->wasChanged('status')) {
            $this->maybeNotify($invoice);
        }
    }

    private function maybeNotify(Invoice $invoice): void
    {
        if ($invoice->status !== Invoice::STATUS_FINALIZED) {
            return; // skip draft / cancelled
        }

        DB::afterCommit(function () use ($invoice) {
            try {
                // invoices has no created_by; the operator is user_id (fallback
                // finalized_by). Resolve the name for the push body.
                $actorId   = $invoice->user_id ?? $invoice->finalized_by;
                $actorName = ($actorId ? optional(User::find($actorId))->name : null) ?? 'Staff';

                app(SaleNotificationService::class)->dispatchForSale(
                    shopId:       (int) $invoice->shop_id,
                    counterType:  'pos',
                    invoiceType:  'invoice',
                    invoiceId:    (int) $invoice->id,
                    amount:       (float) $invoice->total,
                    actorName:    $actorName,
                    customerName: optional($invoice->customer)->name, // null = walk-in
                );
            } catch (\Throwable $e) {
                Log::warning('Sale notification (invoice) failed: ' . $e->getMessage());
            }
        });
    }
}
