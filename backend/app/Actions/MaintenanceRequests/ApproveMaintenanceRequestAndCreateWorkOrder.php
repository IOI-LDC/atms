<?php

namespace App\Actions\MaintenanceRequests;

use App\Actions\Assets\UpdateAssetLocation;
use App\Actions\WorkOrders\ApplyWorkOrderAssetStatusTransition;
use App\Actions\WorkOrders\AssignWorkOrder;
use App\Actions\WorkOrders\SnapshotFormTemplateIntoWorkOrder;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\OperationalStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\BusinessNumberSequence;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MaintenanceRequests\MaintenanceRequestApprovedNotification;
use App\Services\Audit\AuditLogger;
use App\Support\Assets\AssetWorkEligibility;
use App\Support\FrontendUrl;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class ApproveMaintenanceRequestAndCreateWorkOrder
{
    /**
     * @param  int|null  $moveToLocationId  Q4 (2026-08-16): where the asset should
     *                                      be sent for the work. Null keeps its
     *                                      current location, which is the default
     *                                      — LDC's normal destination is the
     *                                      Tajoura Base yard, but that is a choice
     *                                      the approver makes, not a constant.
     *                                      Applies to corrective and preventive
     *                                      requests alike, and the move is part of
     *                                      the same transaction: a failed approval
     *                                      must not leave the asset relocated.
     */
    public function execute(
        MaintenanceRequest $maintenanceRequest,
        int $approvedByUserId,
        ?int $assignToUserId = null,
        ?bool $isFailure = null,
        ?int $moveToLocationId = null
    ): MaintenanceRequest {
        $asset = $maintenanceRequest->asset;

        // Covers preventive approvals too: a PM request raised before the asset
        // left the programme can still be sitting in the queue afterwards.
        AssetWorkEligibility::guard($asset, 'approve a maintenance request');

        return DB::transaction(function () use ($maintenanceRequest, $approvedByUserId, $assignToUserId, $isFailure, $moveToLocationId, $asset) {
            $logger = app(AuditLogger::class);
            $locked = MaintenanceRequest::where('id', $maintenanceRequest->id)->lockForUpdate()->first();

            $before = $locked->toArray();

            if ($locked->status !== MaintenanceRequestStatus::PENDING_REVIEW) {
                throw new DomainException('Only pending review requests can be approved.');
            }

            $locked->update([
                'status' => MaintenanceRequestStatus::CONVERTED,
                'reviewed_by' => $approvedByUserId,
                'reviewed_at' => now(),
                'is_failure' => $isFailure,
            ]);

            $woNumber = BusinessNumberSequence::next('WO', 'WO-');

            $workOrder = WorkOrder::create([
                'number' => $woNumber,
                'maintenance_request_id' => $locked->id,
                'asset_id' => $locked->asset_id,
                'status' => WorkOrderStatus::OPEN,
                'priority' => $locked->priority,
                'description' => $locked->description,
            ]);

            // WO Forms: snapshot the asset's active FormTemplate (if any) into
            // the new Work Order. Self-contained copy; no-op when no template.
            app(SnapshotFormTemplateIntoWorkOrder::class)->execute($workOrder);

            // Asset operational status: a corrective order reports a fault -
            // mark the asset FAILURE unless it is already UNDER_MAINTENANCE (e.g.
            // a concurrent PM). Preventive orders change nothing here; the asset
            // goes UNDER_MAINTENANCE when the WO is started.
            if (! $locked->is_preventive) {
                app(ApplyWorkOrderAssetStatusTransition::class)
                    ->execute($workOrder, OperationalStatus::FAILURE, [OperationalStatus::UNDER_MAINTENANCE]);
            }

            // Q4: optionally send the asset somewhere as part of approving the
            // work. Deliberately after the status transition — a corrective
            // approval has just marked the asset `failure`, and the move must
            // not be re-interpreted by the location rules as a field exit.
            $this->moveAssetForApproval($asset, $moveToLocationId, $locked, $approvedByUserId);

            // WO-02: optionally assign in the same transaction so approval and
            // assignment are atomic. Reuses AssignWorkOrder for the role rule
            // (active Technician or Maintenance Manager) and the audit event.
            // A failed assignment rolls back the whole conversion.
            if ($assignToUserId !== null) {
                app(AssignWorkOrder::class)->execute($workOrder, $assignToUserId, $approvedByUserId);
            }

            $after = $locked->fresh()->toArray();
            $logger->log('maintenance_request.approved', $locked, $before, $after);

            $this->notifyApproval($locked->fresh(), $workOrder, $assignToUserId);

            return $locked->fresh();
        });
    }

    private function notifyApproval(MaintenanceRequest $mr, WorkOrder $workOrder, ?int $assignToUserId): void
    {
        $mr->load('asset', 'createdBy');

        $recipientEmails = collect();

        if ($mr->createdBy?->email) {
            $recipientEmails->push($mr->createdBy->email);
        }

        if ($assignToUserId !== null) {
            $assignee = User::find($assignToUserId);
            if ($assignee?->email) {
                $recipientEmails->push($assignee->email);
            }
        }

        if ($recipientEmails->isEmpty()) {
            return;
        }

        Notification::route('account_email', 'workflow@atms')
            ->notify(new MaintenanceRequestApprovedNotification(
                recipientEmails: $recipientEmails->unique()->values()->all(),
                mrNumber: $mr->number,
                woNumber: $workOrder->number,
                assetName: $mr->asset?->name ?? 'Unknown asset',
                actionUrl: FrontendUrl::to('/work-orders/'.$workOrder->id),
            ));
    }

    /**
     * Q4: move the asset as part of approving the request.
     *
     * `$applyStatusRules = false` for the same reason `StartWorkOrder` passes it:
     * this move is owned by the workflow, which has just set the asset's status
     * deliberately. Letting the location rules run would either overwrite that
     * with `at_the_field`, or read a rig-to-yard approval as a field exit and
     * stamp `need_inspection` on an asset that is about to be worked on anyway.
     *
     * @throws DomainException when the location does not exist or is inactive —
     *                         inside the transaction, so the approval rolls back
     *                         whole rather than converting the request and then
     *                         failing to move the asset.
     */
    private function moveAssetForApproval(
        ?Asset $asset,
        ?int $moveToLocationId,
        MaintenanceRequest $mr,
        int $approvedByUserId
    ): void {
        if ($moveToLocationId === null || $asset === null) {
            return;
        }

        $location = Location::find($moveToLocationId);

        if ($location === null) {
            throw new DomainException('The selected location does not exist.');
        }

        if (! $location->is_active) {
            throw new DomainException('Cannot move an asset to an inactive location.');
        }

        app(UpdateAssetLocation::class)->execute(
            $asset,
            $location,
            'Approved maintenance request '.$mr->number,
            null,
            $approvedByUserId,
            applyStatusRules: false,
        );
    }
}
