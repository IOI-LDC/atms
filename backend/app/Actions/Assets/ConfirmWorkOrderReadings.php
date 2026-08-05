<?php

namespace App\Actions\Assets;

use App\Models\AssetMeterReading;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;

class ConfirmWorkOrderReadings
{
    /**
     * Confirm every unverified reading recorded against a work order.
     *
     * Verification is a by-product of the work order lifecycle rather than a task
     * of its own: closing a work order is Administrator/Maintenance Manager only,
     * so the closer is a genuine second pair of eyes over the technician who took
     * the reading. A manual "verify" button would be clicked by the same person
     * who entered the value, and a step nobody owns is a step that never happens.
     *
     * @return array{confirmed: int, skipped: int}
     */
    public function execute(WorkOrder $workOrder, int $confirmedByUserId): array
    {
        // Ascending order is load-bearing. ConfirmMeterReading rejects a reading
        // dated earlier than the latest confirmed one in its series, so confirming
        // newest-first would make each reading strand the one below it.
        $readings = AssetMeterReading::where('work_order_id', $workOrder->id)
            ->whereNull('confirmed_at')
            ->orderBy('reading_at')
            ->orderBy('id')
            ->get();

        $confirm = app(ConfirmMeterReading::class);
        $logger = app(AuditLogger::class);

        $confirmed = 0;
        $skipped = 0;

        foreach ($readings as $reading) {
            try {
                $confirm->execute($reading, $confirmedByUserId);
                $confirmed++;
            } catch (DomainException $e) {
                // A data-quality problem must never block an operational
                // transition: one out-of-order reading cannot stop a manager from
                // closing a work order. Skip it and leave it unverified.
                //
                // This is safe because ConfirmMeterReading opens its own
                // DB::transaction, which nests as a savepoint inside the caller's.
                // The rollback unwinds that one reading, not the close. Flatten the
                // nesting and every skip becomes a full rollback instead.
                $skipped++;
                $logger->log('meter_reading.confirm_skipped', $reading, [], [
                    'work_order_id' => $workOrder->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return ['confirmed' => $confirmed, 'skipped' => $skipped];
    }
}
