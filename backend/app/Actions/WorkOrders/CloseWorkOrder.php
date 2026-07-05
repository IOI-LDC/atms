<?php

namespace App\Actions\WorkOrders;

use App\Actions\WorkOrders\ApplyWorkOrderAssetStatusTransition;
use App\Enums\OperationalStatus;
use App\Enums\PmTriggerType;
use App\Enums\WorkOrderStatus;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class CloseWorkOrder
{
    public function execute(WorkOrder $workOrder, int $closedByUserId, ?bool $isFailureOverride = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $closedByUserId, $isFailureOverride) {
            $logger = app(AuditLogger::class);
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if ($locked->status !== WorkOrderStatus::COMPLETED) {
                throw new DomainException('Only completed work orders can be closed.');
            }

            $before = $workOrder->toArray();
            $locked->update([
                'status' => WorkOrderStatus::CLOSED,
                'closed_by_user_id' => $closedByUserId,
                'closed_at' => now(),
            ]);
            $after = $workOrder->fresh()->toArray();
            $logger->log('work_order.closed', $locked, $before, $after);

            // Revert the asset to ACTIVE on close - but only from a workflow
            // state (DOWN / UNDER_MAINTENANCE). Never un-retire INACTIVE and
            // no-op if already ACTIVE.
            app(ApplyWorkOrderAssetStatusTransition::class)
                ->execute($locked, OperationalStatus::ACTIVE, [OperationalStatus::ACTIVE, OperationalStatus::INACTIVE]);

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

            return $locked->fresh();
        });
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
}
