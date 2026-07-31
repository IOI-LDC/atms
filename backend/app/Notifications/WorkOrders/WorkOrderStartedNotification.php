<?php

namespace App\Notifications\WorkOrders;

use App\Notifications\Concerns\AccountEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class WorkOrderStartedNotification extends Notification implements ShouldQueueAfterCommit
{
    use AccountEmailNotification, Queueable;

    /**
     * @param  array<int, string>  $managerEmails
     */
    public function __construct(
        public array $managerEmails,
        public string $woNumber,
        public string $assetName,
        public string $technicianName,
        public string $actionUrl,
    ) {}

    public function toAccountEmail(object $notifiable): array
    {
        return [
            'to' => $this->managerEmails,
            'subject' => "Work Order {$this->woNumber} Started",
            'templateData' => [
                'heading' => "Work Order {$this->woNumber} In Progress",
                'notificationType' => 'Work order',
                'recipientName' => 'Maintenance Team',
                'bodyMessage' => "Work order {$this->woNumber} for {$this->assetName} has been started by {$this->technicianName}.",
                'grid' => [
                    ['label' => 'Work Order', 'value' => $this->woNumber],
                    ['label' => 'Asset', 'value' => $this->assetName],
                    ['label' => 'Technician', 'value' => $this->technicianName],
                    ['label' => 'Status', 'value' => 'In Progress'],
                ],
                'descriptionLabel' => 'Info',
                'descriptionValue' => 'The asset is now marked as under maintenance.',
                'actionLabel' => 'View work order',
                'actionUrl' => $this->actionUrl,
            ],
        ];
    }
}
