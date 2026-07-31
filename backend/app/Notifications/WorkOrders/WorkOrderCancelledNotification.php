<?php

namespace App\Notifications\WorkOrders;

use App\Notifications\Concerns\AccountEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class WorkOrderCancelledNotification extends Notification implements ShouldQueueAfterCommit
{
    use AccountEmailNotification, Queueable;

    public function __construct(
        public string $technicianEmail,
        public string $woNumber,
        public string $assetName,
        public string $reason,
        public string $actionUrl,
    ) {}

    public function toAccountEmail(object $notifiable): array
    {
        return [
            'to' => [$this->technicianEmail],
            'subject' => "Work Order {$this->woNumber} Cancelled",
            'templateData' => [
                'heading' => "Work Order {$this->woNumber} Cancelled",
                'notificationType' => 'Work order',
                'recipientName' => 'Team',
                'bodyMessage' => "Work order {$this->woNumber} for {$this->assetName} has been cancelled.",
                'grid' => [
                    ['label' => 'Work Order', 'value' => $this->woNumber],
                    ['label' => 'Asset', 'value' => $this->assetName],
                    ['label' => 'Status', 'value' => 'Cancelled'],
                    ['label' => 'Reason', 'value' => $this->reason],
                ],
                'descriptionLabel' => 'Details',
                'descriptionValue' => $this->reason,
                'actionLabel' => 'View work order',
                'actionUrl' => $this->actionUrl,
            ],
        ];
    }
}
