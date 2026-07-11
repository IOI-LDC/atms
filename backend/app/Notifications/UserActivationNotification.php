<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class UserActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $activationUrl
    ) {}

    public function via(object $notifiable): array
    {
        return ['account_email'];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(object $notifiable, string $channel): array
    {
        if ($channel !== 'account_email') {
            return [];
        }

        return [(new WithoutOverlapping('account-email-graph-mailbox'))
            ->shared()
            ->releaseAfter(10)
            ->expireAfter(120)];
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
