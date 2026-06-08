<?php

namespace App\Actions\Pm;

use App\Enums\PmTriggerType;
use App\Models\MaintenanceRequest;
use App\Models\PmOccurrenceSuppression;
use App\Models\PmRule;
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
        return DB::transaction(function () use ($maintenanceRequest, $rule, $decidedByUserId, $decisionType, $suppressedUntilDate, $suppressedUntilReading, $reason) {
            $suppressions = [];

            if ($maintenanceRequest->triggered_by_date) {
                $suppressions[] = PmOccurrenceSuppression::create([
                    'pm_rule_id' => $rule->id,
                    'asset_id' => $rule->asset_id,
                    'maintenance_request_id' => $maintenanceRequest->id,
                    'trigger_type' => PmTriggerType::DATE->value,
                    'decision_type' => $decisionType,
                    'triggered_by_date' => true,
                    'triggered_by_reading' => false,
                    'suppressed_until_date' => $suppressedUntilDate ?? now()->addDays($rule->interval_days ?? 30)->toDateString(),
                    'decided_by' => $decidedByUserId,
                    'decided_at' => now(),
                    'reason' => $reason,
                ]);
            }

            if ($maintenanceRequest->triggered_by_reading) {
                $suppressions[] = PmOccurrenceSuppression::create([
                    'pm_rule_id' => $rule->id,
                    'asset_id' => $rule->asset_id,
                    'maintenance_request_id' => $maintenanceRequest->id,
                    'trigger_type' => PmTriggerType::READING->value,
                    'decision_type' => $decisionType,
                    'triggered_by_date' => false,
                    'triggered_by_reading' => true,
                    'suppressed_until_reading' => $suppressedUntilReading ?? ($rule->last_triggered_reading + $rule->interval_reading),
                    'decided_by' => $decidedByUserId,
                    'decided_at' => now(),
                    'reason' => $reason,
                ]);
            }

            return $suppressions[0] ?? $maintenanceRequest;
        });
    }
}
