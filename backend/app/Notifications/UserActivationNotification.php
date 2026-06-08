<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class UserActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $activationUrl
    ) {}

    public function via(object $notifiable): array
    {
        return ['account_email'];
    }

    public function toAccountEmail(object $notifiable): array
    {
        return [
            'recipient' => $notifiable->email,
            'subject' => 'Activate your ATMS account',
            'actionUrl' => $this->activationUrl,
        ];
    }
}
