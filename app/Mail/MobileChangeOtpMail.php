<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MobileChangeOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public string $newMobileMasked,
        public string $userName,
        public string $appName
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->otp . ' — confirm your new login mobile',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.mobile-change-otp');
    }
}
