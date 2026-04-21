<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MobileChangedNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $oldMobileMasked,
        public string $newMobileMasked,
        public string $userName,
        public string $changedBy,
        public string $ipAddress,
        public string $appName,
        public ?string $reason = null
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your login mobile number was changed',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.mobile-changed-notification');
    }
}
