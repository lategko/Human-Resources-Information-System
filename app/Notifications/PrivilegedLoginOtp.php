<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;

class PrivilegedLoginOtp extends Notification implements ShouldQueue
{
    use Queueable;
    public function __construct(public readonly string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your HIRS sign-in verification code')
            ->line('Use this verification code to complete your privileged HIRS sign-in:')
            ->line($this->code)
            ->line('This code expires in 10 minutes and can only be used once.')
            ->line('If you did not attempt to sign in, change your password and contact your system administrator.');
    }
}
