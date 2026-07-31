<?php

namespace App\Notifications;

use App\Notifications\Concerns\AccountEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class UserActivationNotification extends Notification implements ShouldQueueAfterCommit
{
    use AccountEmailNotification, Queueable;

    public function __construct(
        public string $activationUrl
    ) {}

    public function toAccountEmail(object $notifiable): array
    {
        return [
            'to' => [$notifiable->email],
            'subject' => 'Activate your ATMS account',
            'templateData' => [
                'heading' => 'Activate your ATMS account',
                'notificationType' => 'Account notification',
                'recipientName' => Str::before($notifiable->email, '@'),
                'bodyMessage' => 'Your ATMS account has been created. Use the button below to activate it and set your password.',
                'grid' => [
                    ['label' => 'System', 'value' => 'ATMS'],
                    ['label' => 'Action', 'value' => 'Account activation'],
                    ['label' => 'Recipient', 'value' => $notifiable->email],
                    ['label' => 'Security', 'value' => 'One-time link'],
                ],
                'descriptionLabel' => 'Important',
                'descriptionValue' => 'If you did not expect this email, you can safely ignore it.',
                'actionLabel' => 'Activate account',
                'actionUrl' => $this->activationUrl,
            ],
        ];
    }
}
