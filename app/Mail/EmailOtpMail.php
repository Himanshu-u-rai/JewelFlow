<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;
    public string $shopName;

    public function __construct(string $otp, string $shopName)
    {
        $this->otp      = $otp;
        $this->shopName = $shopName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->otp . ' is your JewelFlow verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-otp',
        );
    }
}
