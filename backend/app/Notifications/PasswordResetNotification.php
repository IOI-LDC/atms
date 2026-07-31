<?php

namespace App\Notifications;

use App\Notifications\Concerns\AccountEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class PasswordResetNotification extends Notification implements ShouldQueueAfterCommit
{
    use AccountEmailNotification, Queueable;

    public function __construct(
        public string $resetUrl
    ) {}

    public function toAccountEmail(object $notifiable): array
    {
        return [
            'to' => [$notifiable->email],
            'subject' => 'Reset your ATMS password',
            'templateData' => [
                'heading' => 'Reset your ATMS password',
                'notificationType' => 'Account notification',
                'recipientName' => Str::before($notifiable->email, '@'),
                'bodyMessage' => 'Use the button below to reset your password. This link will expire shortly.',
                'grid' => [
                    ['label' => 'System', 'value' => 'ATMS'],
                    ['label' => 'Action', 'value' => 'Password reset'],
                    ['label' => 'Recipient', 'value' => $notifiable->email],
                    ['label' => 'Security', 'value' => 'One-time link'],
                ],
                'descriptionLabel' => 'Important',
                'descriptionValue' => 'If you did not request a password reset, you can safely ignore this email.',
                'actionLabel' => 'Reset password',
                'actionUrl' => $this->resetUrl,
            ],
        ];
    }
}
