<?php

namespace App\Actions\WorkOrders;

use App\Actions\Assets\UpdateAssetLocation;
use App\Enums\LocationType;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Location;
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
    /**
     * Location types at which work may legitimately be performed.
     *
     * An asset recorded at a rig or well site while a work order runs is a lie
     * the rest of the system believes: `AssetDeployment` buckets those types as
     * DEPLOYED, so the asset is counted as earning while it is being repaired.
     * The move happens physically — starting the work order is where we make
     * the system record it.
     */
    private const WORK_LOCATION_TYPES = [LocationType::WORKSHOP, LocationType::YARD];

    /**
     * @param  Location|null  $toLocation  Where the asset is being worked on. Required when
     *                                     the asset is not already at a workshop or yard.
     * @param  int|null  $actorUserId  Who is starting the work order — stamped on the
     *                                 location history row when a move happens.
     *
     * Note this path deliberately does NOT consult `AssetPolicy::updateLocation`
     * (administrator / maintenance manager / logistics). Moving the asset to the
     * workshop is part of starting the work order, performed by whoever may start
     * it — technicians included. The standalone `POST /assets/{id}/location` route
     * keeps its narrower policy.
     */
    public function execute(WorkOrder $workOrder, ?Location $toLocation = null, ?int $actorUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $toLocation, $actorUserId) {
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

            // Resolve the work location before anything mutates, so a rejected
            // start leaves the work order untouched.
            $this->resolveWorkLocation($locked, $toLocation, $actorUserId);

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

    /**
     * Ensure the asset is at a workshop or yard, moving it there if a location
     * was supplied. Throws when the asset is elsewhere and none was.
     */
    private function resolveWorkLocation(WorkOrder $workOrder, ?Location $toLocation, ?int $actorUserId): void
    {
        $asset = $workOrder->asset()->first();

        if ($asset === null) {
            return;
        }

        if ($toLocation !== null) {
            if (! $toLocation->is_active) {
                throw new DomainException('Cannot move an asset to an inactive location.');
            }

            if (! $this->isWorkLocation($toLocation->type)) {
                throw new DomainException('Work orders are performed at a workshop or yard. "'.$toLocation->name.'" is neither.');
            }

            app(UpdateAssetLocation::class)->execute(
                $asset,
                $toLocation,
                'Started work order '.$workOrder->number,
                null,
                $actorUserId,
            );

            return;
        }

        $current = $asset->currentLocation()->first();

        // No location, or a type this application does not recognise, both mean
        // "we do not know it is in the right place" — ask rather than assume.
        if (! $this->isWorkLocation($current?->type)) {
            $where = $current === null
                ? 'This asset has no recorded location.'
                : 'This asset is at '.$current->name.'.';

            throw new DomainException($where.' Select the workshop or yard where the work is being performed before starting this work order.');
        }
    }

    private function isWorkLocation(?string $type): bool
    {
        $parsed = LocationType::tryFrom($type ?? '');

        return $parsed !== null && in_array($parsed, self::WORK_LOCATION_TYPES, true);
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
