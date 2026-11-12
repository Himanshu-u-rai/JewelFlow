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
    /** Dhiran mail never says JewelFlows (owner 2026-11-12); the brand follows the host the code was asked from. */
    public string $brand;

    public function __construct(string $otp, string $shopName)
    {
        $this->otp      = $otp;
        $this->shopName = $shopName;
        $this->brand    = \App\Support\Realm::current() === \App\Support\Realm::DHIRAN ? 'Dhiran' : 'JewelFlows';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->otp . " is your {$this->brand} verification code",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-otp',
        );
    }
}
