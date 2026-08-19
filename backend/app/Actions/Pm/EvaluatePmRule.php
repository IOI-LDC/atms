<?php

namespace App\Actions\Pm;

use App\Enums\MaintenanceRequestStatus;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\BusinessNumberSequence;
use App\Models\MaintenanceRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Pm\PmDueCalculator;
use App\Services\Pm\PmEvaluationBatch;
use App\Support\Assets\AssetWorkEligibility;
use DomainException;
use Illuminate\Support\Facades\DB;

class EvaluatePmRule
{
    public function __construct(private PmDueCalculator $calculator) {}

    /**
     * Evaluate one assignment and raise its PM request if it is due.
     *
     * `$batch` is optional: pass it when evaluating many assignments so the
     * readings and suppressions are read once for the whole set instead of
     * per assignment. Omitting it keeps the original per-assignment queries,
     * which is what a single evaluation from the UI wants.
     */
    public function execute(AssetPmAssignment $assignment, int $triggeredByUserId, ?PmEvaluationBatch $batch = null): ?MaintenanceRequest
    {
        return DB::transaction(function () use ($assignment, $triggeredByUserId, $batch) {
            $logger = app(AuditLogger::class);
            $locked = AssetPmAssignment::where('id', $assignment->id)->lockForUpdate()->first();
            $locked->load('pmRule');

            if (! $locked->is_active || ! $locked->pmRule?->is_active) {
                throw new DomainException('Inactive PM assignments cannot be evaluated.');
            }

            // The scheduler filters this population out through
            // AssetWorkEligibility::scope() on scopeEvaluable; the direct and
            // evaluate-all paths reach an assignment by id, so they check here.
            //
            // `$locked` is the *assignment*. `$locked->asset` is a plain lazy
            // read and was never locked — hence lockAndGuard, which takes the
            // asset row after the assignment row.
            AssetWorkEligibility::lockAndGuard($locked->asset, 'evaluate preventive maintenance');

            if ($locked->hasActiveChain()) {
                throw new DomainException('PM assignment already has an active maintenance chain.');
            }

            if (! $this->calculator->isDue($locked, $batch?->readings, $batch?->suppressions)) {
                return null;
            }

            $triggeredByDate = $this->calculator->isTriggeredByDate($locked, $batch?->suppressions);
            $triggeredByReading = $this->calculator->isTriggeredByReading($locked, $batch?->readings, $batch?->suppressions);

            $triggerDate = $triggeredByDate ? now()->toDateString() : null;
            $triggerReadingValue = null;
            $triggerReadingTypeId = null;

            if ($triggeredByReading) {
                $readingTypeId = $locked->pmRule->usage_reading_type_id;
                $latestReading = $batch?->readings->get("{$locked->asset_id}_{$readingTypeId}")
                    ?? AssetMeterReading::where('asset_id', $locked->asset_id)
                        ->where('usage_reading_type_id', $readingTypeId)
                        ->whereNotNull('confirmed_at')
                        ->orderByDesc('reading_at')
                        ->first();
                $triggerReadingValue = $latestReading?->reading_value;
                $triggerReadingTypeId = $readingTypeId;
            }

            $number = BusinessNumberSequence::next('MR', 'MR-');

            $mr = MaintenanceRequest::create([
                'number' => $number,
                'asset_id' => $locked->asset_id,
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
