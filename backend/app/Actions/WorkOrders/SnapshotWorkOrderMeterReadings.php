<?php

namespace App\Actions\WorkOrders;

use App\Models\AssetMeterReading;
use App\Models\WorkOrder;
use App\Models\WorkOrderMeterSnapshot;
use App\Services\Audit\AuditLogger;

class SnapshotWorkOrderMeterReadings
{
    /**
     * Record the asset's meter position, per reading type, as the work order closes.
     *
     * This is what makes "how many hours since the last repair" answerable. Before
     * it, only services carried a meter position — `last_triggered_reading` on the
     * PM assignment — and repairs left no trace of where the meter stood.
     *
     * Must run *after* ConfirmWorkOrderReadings, so a reading taken on this job and
     * confirmed by this same close is the one captured. Running first would snapshot
     * the previous position and understate every subsequent interval.
     *
     * @return int number of types captured
     */
    public function execute(WorkOrder $workOrder): int
    {
        // Latest confirmed reading per type for this asset. Confirmed only, for the
        // same reason PmDueCalculator uses it: an unconfirmed reading is a claim.
        $latestPerType = AssetMeterReading::where('asset_id', $workOrder->asset_id)
            ->whereNotNull('confirmed_at')
            ->orderBy('usage_reading_type_id')
            ->orderByDesc('reading_at')
            ->orderByDesc('id')
            ->get()
            ->unique('usage_reading_type_id');

        if ($latestPerType->isEmpty()) {
            return 0;
        }

        $logger = app(AuditLogger::class);
        $captured = 0;

        foreach ($latestPerType as $reading) {
            // updateOrCreate rather than create: the unique index would otherwise
            // turn a re-close into a 500. Closed is terminal today, so in practice
            // this writes once.
            $snapshot = WorkOrderMeterSnapshot::updateOrCreate(
                [
                    'work_order_id' => $workOrder->id,
                    'usage_reading_type_id' => $reading->usage_reading_type_id,
                ],
                [
                    'reading_value' => $reading->reading_value,
                    'reading_at' => $reading->reading_at,
                ],
            );

            $logger->log('work_order.meter_snapshot', $snapshot, [], $snapshot->toArray());
            $captured++;
        }

        return $captured;
    }
}
