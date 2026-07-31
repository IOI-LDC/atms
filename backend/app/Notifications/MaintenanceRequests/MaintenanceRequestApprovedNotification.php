<?php

namespace App\Notifications\MaintenanceRequests;

use App\Notifications\Concerns\AccountEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class MaintenanceRequestApprovedNotification extends Notification implements ShouldQueueAfterCommit
{
    use AccountEmailNotification, Queueable;

    /**
     * @param  array<int, string>  $recipientEmails  Requester + assigned technician (if any)
     */
    public function __construct(
        public array $recipientEmails,
        public string $mrNumber,
        public string $woNumber,
        public string $assetName,
        public string $actionUrl,
    ) {}

    public function toAccountEmail(object $notifiable): array
    {
        return [
            'to' => $this->recipientEmails,
            'subject' => "Maintenance Request {$this->mrNumber} Approved",
            'templateData' => [
                'heading' => "Request {$this->mrNumber} Approved",
                'notificationType' => 'Maintenance request',
                'recipientName' => 'Team',
                'bodyMessage' => "Maintenance request {$this->mrNumber} for {$this->assetName} has been approved. Work order {$this->woNumber} has been created.",
                'grid' => [
                    ['label' => 'Request', 'value' => $this->mrNumber],
                    ['label' => 'Work Order', 'value' => $this->woNumber],
                    ['label' => 'Asset', 'value' => $this->assetName],
                    ['label' => 'Status', 'value' => 'Approved'],
                ],
                'descriptionLabel' => 'Next step',
                'descriptionValue' => 'The work order is now open and ready for assignment.',
                'actionLabel' => 'View work order',
                'actionUrl' => $this->actionUrl,
            ],
        ];
    }
}
