<?php

namespace App\Notifications\WorkOrders;

use App\Notifications\Concerns\AccountEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class WorkOrderClosedNotification extends Notification implements ShouldQueueAfterCommit
{
    use AccountEmailNotification, Queueable;

    /**
     * @param  array<int, string>  $ccEmails  Manager(s) who get a CC copy
     */
    public function __construct(
        public string $technicianEmail,
        public array $ccEmails,
        public string $woNumber,
        public string $assetName,
        public string $closedByName,
        public string $actionUrl,
    ) {}

    public function toAccountEmail(object $notifiable): array
    {
        return [
            'to' => [$this->technicianEmail],
            'cc' => $this->ccEmails,
            'subject' => "Work Order {$this->woNumber} Closed",
            'templateData' => [
                'heading' => "Work Order {$this->woNumber} Closed",
                'notificationType' => 'Work order',
                'recipientName' => 'Team',
                'bodyMessage' => "Work order {$this->woNumber} for {$this->assetName} has been closed by {$this->closedByName}. The asset has been returned to active status.",
                'grid' => [
                    ['label' => 'Work Order', 'value' => $this->woNumber],
                    ['label' => 'Asset', 'value' => $this->assetName],
                    ['label' => 'Closed by', 'value' => $this->closedByName],
                    ['label' => 'Status', 'value' => 'Closed'],
                ],
                'descriptionLabel' => 'Info',
                'descriptionValue' => 'No further action is required for this work order.',
                'actionLabel' => 'View work order',
                'actionUrl' => $this->actionUrl,
            ],
        ];
    }
}
