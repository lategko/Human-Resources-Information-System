<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
        $greeting = $notifiable instanceof User && $notifiable->isAdministrator()
            ? 'Hello Admin,'
            : 'Hello '.($notifiable instanceof User ? $notifiable->name : 'there').',';

        return (new MailMessage())
            ->subject('HRIS - Your Login Verification Code')
            ->line($greeting)
            ->line('You have requested to sign in to the Municipality of Camalaniugan Human Resource Information System.')
            ->line('Your verification code is:')
            ->line($this->code)
            ->line('This code expires after 10 minutes.')
            ->line('If you did not initiate this login attempt, please ignore this email.')
            ->line('Municipality of Camalaniugan')
            ->line('Human Resource Information System');
    }
}
