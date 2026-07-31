<?php

namespace App\Notifications\MaintenanceRequests;

use App\Notifications\Concerns\AccountEmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

class MaintenanceRequestSubmittedNotification extends Notification implements ShouldQueueAfterCommit
{
    use AccountEmailNotification, Queueable;

    /**
     * @param  array<int, string>  $managerEmails
     */
    public function __construct(
        public array $managerEmails,
        public string $mrNumber,
        public string $assetName,
        public string $priority,
        public string $requesterName,
        public string $actionUrl,
    ) {}

    public function toAccountEmail(object $notifiable): array
    {
        return [
            'to' => $this->managerEmails,
            'subject' => "New Maintenance Request {$this->mrNumber}",
            'templateData' => [
                'heading' => "New Maintenance Request {$this->mrNumber}",
                'notificationType' => 'Maintenance request',
                'recipientName' => 'Maintenance Team',
                'bodyMessage' => "A new maintenance request has been submitted for {$this->assetName}. Please review it at your earliest convenience.",
                'grid' => [
                    ['label' => 'Request', 'value' => $this->mrNumber],
                    ['label' => 'Asset', 'value' => $this->assetName],
                    ['label' => 'Priority', 'value' => ucfirst($this->priority)],
                    ['label' => 'Requested by', 'value' => $this->requesterName],
                ],
                'descriptionLabel' => 'Action required',
                'descriptionValue' => 'Review and approve or reject this request to proceed.',
                'actionLabel' => 'Review request',
                'actionUrl' => $this->actionUrl,
            ],
        ];
    }
}
