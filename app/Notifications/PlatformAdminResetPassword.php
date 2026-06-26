<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Platform-admin password reset link. Mirrors Laravel's built-in reset
 * notification but points at the platform-admin reset route, and never
 * includes the token anywhere but the signed link itself.
 */
class PlatformAdminResetPassword extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('admin.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutes = config('auth.passwords.platform_admins.expire', 30);

        return (new MailMessage)
            ->subject('JewelFlow Platform Admin — Password Reset')
            ->line('A password reset was requested for your JewelFlow platform admin account.')
            ->action('Reset Password', $url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not request this, no action is needed — your password stays unchanged.');
    }
}
