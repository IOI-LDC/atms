<?php

namespace App\Actions\WorkOrders;

use App\Actions\WorkOrders\ApplyWorkOrderAssetStatusTransition;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\WorkOrders\WorkOrderStartedNotification;
use App\Services\Audit\AuditLogger;
use App\Support\FrontendUrl;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class StartWorkOrder
{
    public function execute(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            $logger = app(AuditLogger::class);
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if ($locked->status !== WorkOrderStatus::OPEN) {
                throw new DomainException('Only open work orders can be started.');
            }

            if ($locked->assigned_to_user_id === null) {
                throw new DomainException('Work order must be assigned before starting.');
            }

            $assignee = User::find($locked->assigned_to_user_id);
            if (! $assignee || ! $assignee->isWorkOrderAssignee()) {
                throw new DomainException('Assigned user is no longer an active Technician or Maintenance Manager. Reassign before starting.');
            }

            $before = $workOrder->toArray();
            $locked->update([
                'status' => WorkOrderStatus::IN_PROGRESS,
                'started_at' => now(),
            ]);
            $after = $workOrder->fresh()->toArray();
            $logger->log('work_order.started', $locked, $before, $after);

            // Force the asset UNDER_MAINTENANCE once work begins (all work orders).
            app(ApplyWorkOrderAssetStatusTransition::class)
                ->execute($locked, OperationalStatus::UNDER_MAINTENANCE);

            $this->notifyManagers($locked->fresh(), $assignee);

            return $locked->fresh();
        });
    }

    private function notifyManagers(WorkOrder $workOrder, User $assignee): void
    {
        $managerEmails = User::where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('code', RoleCode::MAINTENANCE_MANAGER->value))
            ->pluck('email')
            ->filter()
            ->values()
            ->all();

        if (empty($managerEmails)) {
            return;
        }

        $workOrder->load('asset');

        Notification::route('account_email', 'workflow@atms')
            ->notify(new WorkOrderStartedNotification(
                managerEmails: $managerEmails,
                woNumber: $workOrder->number,
                assetName: $workOrder->asset?->name ?? 'Unknown asset',
                technicianName: $assignee->name,
                actionUrl: FrontendUrl::to('/work-orders/'.$workOrder->id),
            ));
    }
}
