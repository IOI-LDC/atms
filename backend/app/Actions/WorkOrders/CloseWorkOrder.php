<?php

namespace App\Actions\WorkOrders;

use App\Enums\PmTriggerType;
use App\Enums\WorkOrderStatus;
use App\Models\AssetMeterReading;
use App\Models\PmRule;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class CloseWorkOrder
{
    public function execute(WorkOrder $workOrder, int $closedByUserId): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $closedByUserId) {
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

            $mr = $locked->maintenanceRequest;
            if ($mr && $mr->pm_rule_id) {
                $pmRule = PmRule::find($mr->pm_rule_id);
                if ($pmRule) {
                    $beforeRule = $pmRule->toArray();
                    $update = ['last_triggered_date' => now()->toDateString()];

                    if ($pmRule->trigger_type === PmTriggerType::READING || $pmRule->trigger_type === PmTriggerType::DATE_OR_READING) {
                        $latestConfirmed = AssetMeterReading::where('asset_id', $pmRule->asset_id)
                            ->where('usage_reading_type_id', $pmRule->usage_reading_type_id)
                            ->whereNotNull('confirmed_at')
                            ->orderByDesc('reading_at')
                            ->value('reading_value');

                        if ($latestConfirmed !== null) {
                            $update['last_triggered_reading'] = $latestConfirmed;
                        }
                    }

                    $pmRule->update($update);
                    $logger->log('close_work_order_update_pm_rule', $pmRule, $beforeRule, $pmRule->fresh()->toArray());

                    $this->resetLowerLevelPmRules($pmRule, $logger);
                }
            }

            return $locked->fresh();
        });
    }

    /**
     * Cumulative maintenance: when a higher-level PM (e.g. L3) closes, reset the
     * baselines of all active lower-level PM rules (L1, L2) on the same asset so
     * the lower-level cycle restarts from this maintenance event. Only applies to
     * the standard L1-L4 levels (parses numeric suffix); custom levels are skipped.
     */
    private function resetLowerLevelPmRules(PmRule $pmRule, AuditLogger $logger): void
    {
        if (! $pmRule->maintenance_level || ! preg_match('/^L([1-4])$/', $pmRule->maintenance_level, $matches)) {
            return;
        }

        $currentLevel = (int) $matches[1];

        $lowerRules = PmRule::where('asset_id', $pmRule->asset_id)
            ->where('id', '!=', $pmRule->id)
            ->where('is_active', true)
            ->get();

        foreach ($lowerRules as $lowerRule) {
            if (! preg_match('/^L([1-4])$/', $lowerRule->maintenance_level ?? '', $lowerMatches)) {
                continue;
            }

            if ((int) $lowerMatches[1] >= $currentLevel) {
                continue;
            }

            $beforeLower = $lowerRule->toArray();
            $reset = ['last_triggered_date' => now()->toDateString()];

            $latestConfirmed = AssetMeterReading::where('asset_id', $lowerRule->asset_id)
                ->where('usage_reading_type_id', $lowerRule->usage_reading_type_id)
                ->whereNotNull('confirmed_at')
                ->orderByDesc('reading_at')
                ->value('reading_value');

            if ($latestConfirmed !== null) {
                $reset['last_triggered_reading'] = $latestConfirmed;
            }

            $lowerRule->update($reset);
            $logger->log('close_work_order_reset_pm_rule', $lowerRule, $beforeLower, $lowerRule->fresh()->toArray());
        }
    }
}
