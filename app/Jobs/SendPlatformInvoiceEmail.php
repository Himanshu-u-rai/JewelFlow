<?php

namespace App\Jobs;

use App\Mail\PlatformInvoiceMail;
use App\Models\Platform\PlatformInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPlatformInvoiceEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    /**
     * Never queue until the surrounding transaction has committed.
     *
     * Two of this job's four dispatch sites (ShopController::store and
     * DhiranOnboardingController) sit inside the shop-creation transaction. A
     * bare dispatch there queues the job while the invoice row is still
     * uncommitted: a worker picks it up, finds nothing, and logs "invoice not
     * found" — or, if the transaction rolls back, the shop never exists but the
     * customer has already been emailed an invoice for it.
     *
     * Declared here rather than as ->afterCommit() at each call site so a fifth
     * dispatch added inside some future transaction is correct by default.
     * Outside a transaction this is a no-op.
     *
     * Set in the constructor rather than declared as a property: Queueable
     * already declares `public $afterCommit;` and PHP rejects a trait/class
     * property pair whose type or default differs.
     */
    public function __construct(public readonly int $invoiceId)
    {
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $invoice = PlatformInvoice::with(['shop', 'plan'])->find($this->invoiceId);

        if (! $invoice) {
            Log::warning('SendPlatformInvoiceEmail: invoice not found', ['invoice_id' => $this->invoiceId]);
            return;
        }

        $shop  = $invoice->shop;
        $email = $shop?->owner_email ?? $shop?->shop_email ?? null;

        if (! $email) {
            Log::info('SendPlatformInvoiceEmail: no email address on shop, skipping', [
                'invoice_id' => $this->invoiceId,
                'shop_id'    => $invoice->shop_id,
            ]);
            return;
        }

        Mail::to($email)->send(new PlatformInvoiceMail($invoice));
    }
}
