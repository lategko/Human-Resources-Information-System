<?php

namespace App\Notifications;

use App\Models\LeaveApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveApplicationUpdated extends Notification
{
    use Queueable;

    public function __construct(
        private readonly LeaveApplication $application,
        private readonly string $message
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'message' => $this->message,
            'application_id' => $this->application->id,
            'status' => $this->application->status,
        ];
    }
}
