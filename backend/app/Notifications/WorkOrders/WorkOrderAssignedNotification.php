<?php

namespace App\Notifications\WorkOrders;

use App\Notifications\Concerns\AccountEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class WorkOrderAssignedNotification extends Notification implements ShouldQueueAfterCommit
{
    use AccountEmailNotification, Queueable;

    public function __construct(
        public string $technicianEmail,
        public string $woNumber,
        public string $assetName,
        public string $priority,
        public string $actionUrl,
    ) {}

    public function toAccountEmail(object $notifiable): array
    {
        return [
            'to' => [$this->technicianEmail],
            'subject' => "Work Order {$this->woNumber} Assigned to You",
            'templateData' => [
                'heading' => "Work Order {$this->woNumber} Assigned",
                'notificationType' => 'Work order',
                'recipientName' => 'Team',
                'bodyMessage' => "Work order {$this->woNumber} for {$this->assetName} has been assigned to you.",
                'grid' => [
                    ['label' => 'Work Order', 'value' => $this->woNumber],
                    ['label' => 'Asset', 'value' => $this->assetName],
                    ['label' => 'Priority', 'value' => ucfirst($this->priority)],
                    ['label' => 'Status', 'value' => 'Open'],
                ],
                'descriptionLabel' => 'Next step',
                'descriptionValue' => 'Start the work order when you are ready to begin.',
                'actionLabel' => 'View work order',
                'actionUrl' => $this->actionUrl,
            ],
        ];
    }
}
