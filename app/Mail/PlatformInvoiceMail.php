<?php

namespace App\Mail;

use App\Models\Platform\PlatformInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PlatformInvoice $invoice)
    {
    }

    public function envelope(): Envelope
    {
        $planName = $this->invoice->plan?->name ?? 'Subscription';
        $invoiceNo = $this->invoice->invoice_number;

        return new Envelope(
            subject: "Invoice {$invoiceNo} — {$planName} | " . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.platform-invoice',
            with: [
                'invoice' => $this->invoice,
                'shop'    => $this->invoice->shop,
                'plan'    => $this->invoice->plan,
            ],
        );
    }
}
