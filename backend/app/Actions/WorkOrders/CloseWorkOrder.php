<?php

namespace App\Actions\WorkOrders;

use App\Enums\PmTriggerType;
use App\Enums\WorkOrderStatus;
use App\Models\AssetMeterReading;
use App\Models\PmRule;
use App\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class CloseWorkOrder
{
    public function execute(WorkOrder $workOrder, int $closedByUserId): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $closedByUserId) {
            $locked = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->first();

            if ($locked->status !== WorkOrderStatus::COMPLETED) {
                throw new DomainException('Only completed work orders can be closed.');
            }

            $locked->update([
                'status' => WorkOrderStatus::CLOSED,
                'closed_by_user_id' => $closedByUserId,
                'closed_at' => now(),
            ]);

            $mr = $locked->maintenanceRequest;
            if ($mr && $mr->pm_rule_id) {
                $pmRule = PmRule::find($mr->pm_rule_id);
                if ($pmRule) {
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
                }
            }

            return $locked->fresh();
        });
    }
}
