<?php

namespace App\Actions\Pm;

use App\Enums\MaintenanceRequestStatus;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\BusinessNumberSequence;
use App\Models\MaintenanceRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Pm\PmDueCalculator;
use DomainException;
use Illuminate\Support\Facades\DB;

class EvaluatePmRule
{
    public function __construct(private PmDueCalculator $calculator) {}

    public function execute(AssetPmAssignment $assignment, int $triggeredByUserId): ?MaintenanceRequest
    {
        return DB::transaction(function () use ($assignment, $triggeredByUserId) {
            $logger = app(AuditLogger::class);
            $locked = AssetPmAssignment::where('id', $assignment->id)->lockForUpdate()->first();
            $locked->load('pmRule');

            if (! $locked->is_active || ! $locked->pmRule?->is_active) {
                throw new DomainException('Inactive PM assignments cannot be evaluated.');
            }

            if ($locked->hasActiveChain()) {
                throw new DomainException('PM assignment already has an active maintenance chain.');
            }

            if (! $this->calculator->isDue($locked)) {
                return null;
            }

            $triggeredByDate = $this->calculator->isTriggeredByDate($locked);
            $triggeredByReading = $this->calculator->isTriggeredByReading($locked);

            $triggerDate = $triggeredByDate ? now()->toDateString() : null;
            $triggerReadingValue = null;
            $triggerReadingTypeId = null;

            if ($triggeredByReading) {
                $latestReading = AssetMeterReading::where('asset_id', $locked->asset_id)
                    ->where('usage_reading_type_id', $locked->pmRule->usage_reading_type_id)
                    ->whereNotNull('confirmed_at')
                    ->orderByDesc('reading_at')
                    ->first();
                $triggerReadingValue = $latestReading?->reading_value;
                $triggerReadingTypeId = $locked->pmRule->usage_reading_type_id;
            }

            $number = BusinessNumberSequence::next('MR', 'MR-');

            $mr = MaintenanceRequest::create([
                'number' => $number,
                'asset_id' => $locked->asset_id,
                'type' => 'preventive',
                'status' => MaintenanceRequestStatus::PENDING_REVIEW,
                'priority' => 'medium',
                'description' => "Auto-generated PM: {$locked->pmRule->name}",
                'created_by' => $triggeredByUserId,
                'is_preventive' => true,
                'pm_rule_id' => $locked->pm_rule_id,
                'triggered_by_date' => $triggeredByDate,
                'triggered_by_reading' => $triggeredByReading,
                'trigger_date' => $triggerDate,
                'trigger_reading_value' => $triggerReadingValue,
                'trigger_reading_type_id' => $triggerReadingTypeId,
            ]);

            $logger->log('evaluate_pm_rule', $mr, [], $mr->toArray());

            return $mr;
        });
    }
}
