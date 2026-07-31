<?php

namespace App\Notifications\MaintenanceRequests;

use App\Notifications\Concerns\AccountEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class MaintenanceRequestRejectedNotification extends Notification implements ShouldQueueAfterCommit
{
    use AccountEmailNotification, Queueable;

    public function __construct(
        public string $requesterEmail,
        public string $mrNumber,
        public string $assetName,
        public string $reason,
        public string $actionUrl,
    ) {}

    public function toAccountEmail(object $notifiable): array
    {
        return [
            'to' => [$this->requesterEmail],
            'subject' => "Maintenance Request {$this->mrNumber} Rejected",
            'templateData' => [
                'heading' => "Request {$this->mrNumber} Rejected",
                'notificationType' => 'Maintenance request',
                'recipientName' => 'Team',
                'bodyMessage' => "Your maintenance request {$this->mrNumber} for {$this->assetName} has been rejected.",
                'grid' => [
                    ['label' => 'Request', 'value' => $this->mrNumber],
                    ['label' => 'Asset', 'value' => $this->assetName],
                    ['label' => 'Status', 'value' => 'Rejected'],
                    ['label' => 'Reason', 'value' => $this->reason],
                ],
                'descriptionLabel' => 'Details',
                'descriptionValue' => $this->reason,
                'actionLabel' => 'View request',
                'actionUrl' => $this->actionUrl,
            ],
        ];
    }
}
