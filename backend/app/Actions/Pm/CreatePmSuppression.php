<?php

namespace App\Actions\Pm;

use App\Models\MaintenanceRequest;
use App\Models\PmOccurrenceSuppression;
use App\Models\PmRule;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreatePmSuppression
{
    public function execute(
        MaintenanceRequest $maintenanceRequest,
        PmRule $rule,
        int $decidedByUserId,
        string $decisionType,
        ?string $suppressedUntilDate = null,
        ?string $suppressedUntilReading = null,
        ?string $reason = null
    ): PmOccurrenceSuppression {
        $this->validate($maintenanceRequest, $suppressedUntilDate, $suppressedUntilReading);

        return DB::transaction(function () use ($maintenanceRequest, $rule, $decidedByUserId, $decisionType, $suppressedUntilDate, $suppressedUntilReading, $reason) {
            $logger = app(AuditLogger::class);
            $triggeredByDate = (bool) $maintenanceRequest->triggered_by_date;
            $triggeredByReading = (bool) $maintenanceRequest->triggered_by_reading;

            $data = [
                'pm_rule_id' => $rule->id,
                'asset_id' => $rule->asset_id,
                'maintenance_request_id' => $maintenanceRequest->id,
                'trigger_type' => $rule->trigger_type->value,
                'decision_type' => $decisionType,
                'triggered_by_date' => $triggeredByDate,
                'triggered_by_reading' => $triggeredByReading,
                'trigger_date' => $triggeredByDate ? $maintenanceRequest->trigger_date?->toDateString() : null,
                'trigger_reading_value' => $triggeredByReading ? $maintenanceRequest->trigger_reading_value : null,
                'trigger_reading_type_id' => $triggeredByReading ? $maintenanceRequest->trigger_reading_type_id : null,
                'suppressed_until_date' => $triggeredByDate ? $suppressedUntilDate : null,
                'suppressed_until_reading' => $triggeredByReading ? $suppressedUntilReading : null,
                'decided_by' => $decidedByUserId,
                'decided_at' => now(),
                'reason' => $reason,
            ];

            $before = [];
            $suppression = PmOccurrenceSuppression::create($data);
            $after = $suppression->toArray();
            $logger->log('create_pm_suppression', $suppression, $before, $after);

            return $suppression;
        });
    }

    private function validate(
        MaintenanceRequest $maintenanceRequest,
        ?string $suppressedUntilDate,
        ?string $suppressedUntilReading
    ): void {
        if ($maintenanceRequest->triggered_by_date && $suppressedUntilDate === null) {
            throw new DomainException('suppressed_until_date is required for date-triggered occurrences.');
        }

        if ($maintenanceRequest->triggered_by_reading && $suppressedUntilReading === null) {
            throw new DomainException('suppressed_until_reading is required for reading-triggered occurrences.');
        }
    }
}