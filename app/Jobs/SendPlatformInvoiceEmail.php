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

    public function __construct(public readonly int $invoiceId)
    {
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
