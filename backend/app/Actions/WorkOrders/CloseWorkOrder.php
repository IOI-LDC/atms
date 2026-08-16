<?php

namespace App\Actions\WorkOrders;

use App\Actions\Assets\ConfirmWorkOrderReadings;
use App\Actions\MaintenanceRequests\CancelMaintenanceRequest;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\OperationalStatus;
use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\MaintenanceRequest;
use App\Models\MasterDataItem;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\WorkOrders\WorkOrderClosedNotification;
use App\Services\Audit\AuditLogger;
use App\Support\Assets\AssetFieldStatus;
use App\Support\FrontendUrl;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CloseWorkOrder
{
    /**
     * Non-blocking notices raised by the last `execute()` call, for the
     * controller to return alongside the closed work order.
     *
     * Deliberately not exceptions: none of these is a reason to refuse a close.
     * An asset that has been repaired has been repaired, whatever ATMS can or
     * cannot say about the paperwork.
     *
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * @param  int|null  $servicedPmAssignmentId  Set when the closer declares that a
     *                                            preventive service was performed
     *                                            alongside this job — the asset was in
     *                                            for a repair, a PM level happened to be
     *                                            due, and the team did both. Resets that
     *                                            assignment's baselines and retires any
     *                                            PM request already raised for it.
     */
    public function execute(
        WorkOrder $workOrder,
        int $closedByUserId,
        ?bool $isFailureOverride = null,
        ?int $servicedPmAssignmentId = null
    ): WorkOrder {
        $this->warnings = [];

        return DB::transaction(function () use ($workOrder, $closedByUserId, $isFailureOverride, $servicedPmAssignmentId) {
            $logger = app(AuditLogger::class);
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if ($locked->status !== WorkOrderStatus::COMPLETED) {
                throw new DomainException('Only completed work orders can be closed.');
            }

            // RQ2 (confirmed with the user 2026-08-16): a work order cannot be
            // closed until it carries the paperwork — typically the completed
            // inspection form as a PDF or spreadsheet.
            //
            // Deliberately gated at CLOSE and not at completion. Completion is
            // the technician saying the physical work is finished, often from a
            // yard or a rig where uploading a file is awkward; closing is the
            // manager signing it off. Putting the gate here lets the technician
            // finish, upload afterwards, and still be the one who supplies the
            // evidence — which is why uploads stay open on a COMPLETED work
            // order (see AttachmentPolicy::uploadToWorkOrder).
            //
            // Any attachment satisfies it. ATMS has no notion of "the inspection
            // form" specifically — that would need an attachment category that
            // does not exist — so this checks presence, not kind. Stated so a
            // future reader does not mistake it for a stricter rule.
            if (! $locked->attachments()->exists()) {
                throw new DomainException(
                    'This work order has no attachments. Upload the completed form or supporting '
                    .'document before closing it.'
                );
            }

            $before = $workOrder->toArray();
            $locked->update([
                'status' => WorkOrderStatus::CLOSED,
                'closed_by_user_id' => $closedByUserId,
                'closed_at' => now(),
            ]);
            $after = $workOrder->fresh()->toArray();
            $logger->log('work_order.closed', $locked, $before, $after);

            // Close always returns the asset to service. The closer used to be
            // able to pick a status here; since the 2026-08-16 vocabulary change
            // that choice is gone, because "closed but still broken" is not a
            // thing — a work order that did not fix the asset is *cancelled*,
            // which is where the choice now lives.
            app(ApplyWorkOrderAssetStatusTransition::class)
                ->execute($locked, OperationalStatus::READY_FOR_FIELD);

            $this->resetCondition($locked, $logger);

            // Confirm this work order's readings before anything reads them back.
            // Both PM baseline queries below (the assignment's own, and the one
            // inside resetLowerLevelAssignments) look up the latest *confirmed*
            // reading, so a reading confirmed here lands in the baseline in the
            // same pass. Moving this call below that block silently breaks both.
            app(ConfirmWorkOrderReadings::class)->execute($locked, $closedByUserId);

            $mr = $locked->maintenanceRequest;

            // Ground-truth override: closing is the second chance to classify
            // is_failure, since the technician has now physically inspected the
            // asset. Only applies to corrective MRs; PM WOs are never failures.
            if ($isFailureOverride !== null && $mr && ! $mr->is_preventive) {
                $mrBefore = $mr->toArray();
                $mr->update(['is_failure' => $isFailureOverride]);
                $logger->log('close_work_order_update_mr_is_failure', $mr, $mrBefore, $mr->fresh()->toArray());
            }

            if ($mr && $mr->pm_rule_id) {
                $assignment = AssetPmAssignment::where('pm_rule_id', $mr->pm_rule_id)
                    ->where('asset_id', $mr->asset_id)
                    ->first();

                if ($assignment) {
                    $assignment->load('pmRule');
                    $beforeAssignment = $assignment->toArray();
                    $update = ['last_triggered_date' => now()->toDateString()];

                    if (in_array($assignment->pmRule?->trigger_type, [PmTriggerType::READING, PmTriggerType::DATE_OR_READING])) {
                        $latestConfirmed = AssetMeterReading::where('asset_id', $assignment->asset_id)
                            ->where('usage_reading_type_id', $assignment->pmRule->usage_reading_type_id)
                            ->whereNotNull('confirmed_at')
                            ->orderByDesc('reading_at')
                            ->value('reading_value');

                        if ($latestConfirmed !== null) {
                            $update['last_triggered_reading'] = $latestConfirmed;
                        }
                    }

                    $assignment->update($update);
                    $logger->log('close_work_order_update_pm_assignment', $assignment, $beforeAssignment, $assignment->fresh()->toArray());

                    $this->resetLowerLevelAssignments($assignment, $logger);
                }
            }

            // A service declared on a repair work order: the asset was already in
            // the workshop, a PM level was due, and the team did both. The branch
            // above only fires for preventive work orders (they carry a pm_rule_id);
            // this is the corrective path, which has none.
            if ($servicedPmAssignmentId !== null) {
                $this->recordDeclaredService($locked, $servicedPmAssignmentId, $closedByUserId, $logger);
            }

            // Capture the meter position *after* readings are confirmed and after any
            // baseline reset, so the snapshot reflects the state this close leaves behind.
            app(SnapshotWorkOrderMeterReadings::class)->execute($locked);

            $this->notifyTechnician($locked->fresh(), $closedByUserId);

            return $locked->fresh();
        });
    }

    private function notifyTechnician(WorkOrder $workOrder, int $closedByUserId): void
    {
        $workOrder->load('asset', 'assignedTo');

        $technicianEmail = $workOrder->assignedTo?->email;

        if (! $technicianEmail) {
            return;
        }

        $closer = User::find($closedByUserId);

        // CC all active managers on the close notification.
        $ccEmails = User::where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('code', RoleCode::MAINTENANCE_MANAGER->value))
            ->pluck('email')
            ->filter()
            ->values()
            ->all();

        Notification::route('account_email', 'workflow@atms')
            ->notify(new WorkOrderClosedNotification(
                technicianEmail: $technicianEmail,
                ccEmails: $ccEmails,
                woNumber: $workOrder->number,
                assetName: $workOrder->asset?->name ?? 'Unknown asset',
                closedByName: $closer?->name ?? 'A manager',
                actionUrl: FrontendUrl::to('/work-orders/'.$workOrder->id),
            ));
    }

    /**
     * Record a preventive service performed under a work order that is not itself a
     * PM job — typically a repair, where the asset was already stripped down and a
     * due service was done at the same time.
     *
     * Does three things: resets the declared assignment's date and reading baselines
     * (so the next occurrence lands a full interval out), cascades to lower levels
     * through the same rule the preventive path uses, and retires any PM request
     * already raised for that assignment so nobody approves a second work order for
     * work that is done.
     */
    private function recordDeclaredService(
        WorkOrder $workOrder,
        int $assignmentId,
        int $closedByUserId,
        AuditLogger $logger
    ): void {
        $assignment = AssetPmAssignment::where('id', $assignmentId)->lockForUpdate()->first();

        if (! $assignment || $assignment->asset_id !== $workOrder->asset_id) {
            throw new DomainException('That service schedule does not belong to this work order\'s asset.');
        }

        if (! $assignment->is_active) {
            throw new DomainException('That service schedule is not active.');
        }

        $assignment->load('pmRule');
        $before = $assignment->toArray();
        $update = ['last_triggered_date' => now()->toDateString()];

        if (in_array($assignment->pmRule?->trigger_type, [PmTriggerType::READING, PmTriggerType::DATE_OR_READING])) {
            $latestConfirmed = AssetMeterReading::where('asset_id', $assignment->asset_id)
                ->where('usage_reading_type_id', $assignment->pmRule->usage_reading_type_id)
                ->whereNotNull('confirmed_at')
                ->orderByDesc('reading_at')
                ->value('reading_value');

            if ($latestConfirmed !== null) {
                $update['last_triggered_reading'] = $latestConfirmed;
            }
        }

        $assignment->update($update);
        $logger->log('work_order.service_declared', $assignment, $before, $assignment->fresh()->toArray(), [
            'work_order_id' => $workOrder->id,
            'work_order_number' => $workOrder->number,
        ]);

        $this->resetLowerLevelAssignments($assignment->fresh()->load('pmRule'), $logger);
        $this->retirePendingPmRequest($workOrder, $assignment->fresh(), $closedByUserId, $logger);
    }

    /**
     * Cancel a still-pending PM request for an assignment whose service was just
     * declared as done under another work order.
     *
     * The suppression window is derived from the *new* baseline, so it says "not due
     * again until the next real occurrence" rather than an arbitrary skip. The
     * decision type is `performed_under_repair`, not `cancelled`, so PM compliance
     * reporting does not read this as a skipped service.
     */
    private function retirePendingPmRequest(
        WorkOrder $workOrder,
        AssetPmAssignment $assignment,
        int $closedByUserId,
        AuditLogger $logger
    ): void {
        $pending = MaintenanceRequest::where('asset_id', $assignment->asset_id)
            ->where('pm_rule_id', $assignment->pm_rule_id)
            ->where('is_preventive', true)
            ->where('status', MaintenanceRequestStatus::PENDING_REVIEW)
            ->lockForUpdate()
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $rule = $assignment->pmRule;
        $untilDate = $rule?->interval_days
            ? now()->addDays((int) $rule->interval_days)->toDateString()
            : null;
        $untilReading = ($rule?->interval_reading !== null && $assignment->last_triggered_reading !== null)
            ? (string) ((float) $assignment->last_triggered_reading + (float) $rule->interval_reading)
            : null;

        $reason = "Service performed under {$workOrder->number}, closed ".now()->toDateString().'.';

        foreach ($pending as $mr) {
            try {
                app(CancelMaintenanceRequest::class)->execute(
                    $mr,
                    $closedByUserId,
                    $reason,
                    $untilDate,
                    $untilReading,
                    'performed_under_repair'
                );
            } catch (DomainException $e) {
                // Never let PM housekeeping block a close — same rule as reading
                // confirmation. A request that moved on between the read and the
                // lock simply stays as it is, and the skip is visible in the log.
                $logger->log('work_order.service_declared_pm_retire_skipped', $mr, [], [
                    'work_order_id' => $workOrder->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Cumulative maintenance: when a higher-level PM (e.g. L3) closes, reset the
     * baselines of all active lower-level assignments (L1, L2) on the same asset
     * so the lower-level cycle restarts from this maintenance event. Only applies
     * to the standard L1-L4 levels (parses numeric suffix); custom levels skipped.
     */
    private function resetLowerLevelAssignments(AssetPmAssignment $assignment, AuditLogger $logger): void
    {
        $level = $assignment->pmRule?->maintenance_level;

        if (! $level || ! preg_match('/^L([1-4])$/', $level, $matches)) {
            return;
        }

        $currentLevel = (int) $matches[1];

        $lowerAssignments = AssetPmAssignment::where('asset_id', $assignment->asset_id)
            ->where('id', '!=', $assignment->id)
            ->where('is_active', true)
            ->with('pmRule')
            ->get();

        foreach ($lowerAssignments as $lowerAssignment) {
            if (! preg_match('/^L([1-4])$/', $lowerAssignment->pmRule?->maintenance_level ?? '', $lowerMatches)) {
                continue;
            }

            if ((int) $lowerMatches[1] >= $currentLevel) {
                continue;
            }

            $beforeLower = $lowerAssignment->toArray();
            $reset = ['last_triggered_date' => now()->toDateString()];

            if (in_array($lowerAssignment->pmRule?->trigger_type, [PmTriggerType::READING, PmTriggerType::DATE_OR_READING])) {
                $latestConfirmed = AssetMeterReading::where('asset_id', $lowerAssignment->asset_id)
                    ->where('usage_reading_type_id', $lowerAssignment->pmRule->usage_reading_type_id)
                    ->whereNotNull('confirmed_at')
                    ->orderByDesc('reading_at')
                    ->value('reading_value');

                if ($latestConfirmed !== null) {
                    $reset['last_triggered_reading'] = $latestConfirmed;
                }
            }

            $lowerAssignment->update($reset);
            $logger->log('close_work_order_reset_pm_assignment', $lowerAssignment, $beforeLower, $lowerAssignment->fresh()->toArray());
        }
    }

    /**
     * Return the asset's condition to the vocabulary's default.
     *
     * The condition records what is wrong with an asset — Need Assembly,
     * Missing Parts, Need Inspection. Closing the work order is the moment those
     * stop being true, so leaving the old value would have every repaired asset
     * still advertising the fault it came in with.
     *
     * Two deliberate restraints:
     *  - **Only close resets.** Cancel does not: a cancelled job did not fix
     *    anything, so the condition it came in with still stands.
     *  - **Never writes null.** With no resolvable default the condition is left
     *    exactly as it was and the omission is reported, rather than clearing a
     *    value nobody replaced.
     */
    private function resetCondition(WorkOrder $workOrder, AuditLogger $logger): void
    {
        $asset = $workOrder->asset()->lockForUpdate()->first();

        if ($asset === null) {
            return;
        }

        $previous = $asset->condition_status;

        // An asset that came back from the field flagged for inspection, and is
        // now being closed out, is the case RQ1 (Phase 6) exists to tighten:
        // once PM marking is built this should only warn when no PM level was
        // marked. Until then it warns on the condition alone, which is the
        // half that is knowable today.
        if ($previous === AssetFieldStatus::NEED_INSPECTION) {
            $this->warnings[] = 'This asset was flagged Need Inspection. Confirm the inspection was carried out '
                .'and the relevant PM level recorded before relying on its schedule.';
        }

        $default = MasterDataItem::defaultFor(MasterDataItem::ASSET_CONDITIONS);

        if ($default === null) {
            $this->warnings[] = 'The asset condition could not be reset: no default condition is configured.';

            $logger->log('asset.condition_reset_skipped', $asset, [], [], [
                'work_order_id' => $workOrder->id,
                'reason' => 'no_active_default',
            ]);

            return;
        }

        if ($previous === $default->value) {
            return;
        }

        $before = $asset->toArray();
        $asset->update(['condition_status' => $default->value]);

        $logger->log('asset.condition_reset', $asset, $before, $asset->fresh()->toArray(), [
            'work_order_id' => $workOrder->id,
            'from' => $previous,
            'to' => $default->value,
        ]);
    }
}
