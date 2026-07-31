<?php

namespace App\Notifications\Concerns;

use App\Support\Jobs\OverlapKeys;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Shared queue behaviour for every outbound email.
 *
 * The using class must implement `ShouldQueueAfterCommit` rather than plain
 * `ShouldQueue`, so a transition that rolls back cannot emit mail about something
 * that never happened. That cannot be enforced here — a trait cannot add an
 * interface — but `AccountEmailNotificationContractTest` asserts it for every
 * notification in the application.
 */
trait AccountEmailNotification
{
    public int $tries = 10;

    public array $backoff = [30, 120, 300];

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

        return [(new WithoutOverlapping(OverlapKeys::ACCOUNT_EMAIL))
            ->shared()
            ->releaseAfter(10)
            ->expireAfter(120)];
    }
}
