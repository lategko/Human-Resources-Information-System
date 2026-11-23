<?php

namespace App\Notifications;

use App\Models\OfficeHeadAvailability;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OfficeHeadAvailabilityUpdated extends Notification
{
    use Queueable;

    public function __construct(
        private readonly OfficeHeadAvailability $availability,
        private readonly string $message
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'message' => $this->message,
            'availability_id' => $this->availability->id,
            'department_id' => $this->availability->department_id,
            'status' => $this->availability->availability_status,
            'availability_url' => $notifiable instanceof User
                && ($notifiable->isAdministrator() || $notifiable->id === $this->availability->office_head_user_id)
                    ? route('office-head-availability.index')
                    : null,
        ];
    }
}
