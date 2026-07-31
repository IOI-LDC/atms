<?php

namespace App\Notifications\WorkOrders;

use App\Notifications\Concerns\AccountEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class WorkOrderCompletedNotification extends Notification implements ShouldQueueAfterCommit
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
            'subject' => "Work Order {$this->woNumber} Completed",
            'templateData' => [
                'heading' => "Work Order {$this->woNumber} Completed",
                'notificationType' => 'Work order',
                'recipientName' => 'Maintenance Team',
                'bodyMessage' => "Work order {$this->woNumber} for {$this->assetName} has been completed by {$this->technicianName}. It is now awaiting your review and closure.",
                'grid' => [
                    ['label' => 'Work Order', 'value' => $this->woNumber],
                    ['label' => 'Asset', 'value' => $this->assetName],
                    ['label' => 'Completed by', 'value' => $this->technicianName],
                    ['label' => 'Status', 'value' => 'Completed'],
                ],
                'descriptionLabel' => 'Action required',
                'descriptionValue' => 'Review the completion notes and close the work order to finalize.',
                'actionLabel' => 'Review & close',
                'actionUrl' => $this->actionUrl,
            ],
        ];
    }
}
