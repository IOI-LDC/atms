<?php

namespace App\Actions\Pm;

use App\Models\BusinessNumberSequence;
use App\Models\MaintenanceRequest;
use App\Models\PmRule;
use App\Services\Pm\PmDueCalculator;
use DomainException;
use Illuminate\Support\Facades\DB;

class EvaluatePmRule
{
    public function __construct(private PmDueCalculator $calculator) {}

    public function execute(PmRule $rule, int $triggeredByUserId): ?MaintenanceRequest
    {
        return DB::transaction(function () use ($rule, $triggeredByUserId) {
            $locked = PmRule::where('id', $rule->id)->lockForUpdate()->first();

            if (! $locked->is_active) {
                throw new DomainException('Inactive PM rules cannot be evaluated.');
            }

            if ($locked->hasActiveChain()) {
                throw new DomainException('PM rule already has an active maintenance chain.');
            }

            if (! $this->calculator->isDue($locked)) {
                return null;
            }

            $triggeredByDate = $this->calculator->isTriggeredByDate($locked);
            $triggeredByReading = $this->calculator->isTriggeredByReading($locked);

            $number = BusinessNumberSequence::next('MR', 'MR-');

            $mr = MaintenanceRequest::create([
                'number' => $number,
                'asset_id' => $locked->asset_id,
                'type' => 'preventive',
                'status' => 'pending_review',
                'priority' => 'medium',
                'description' => "Auto-generated PM: {$locked->name}",
                'created_by' => $triggeredByUserId,
                'is_preventive' => true,
                'pm_rule_id' => $locked->id,
                'triggered_by_date' => $triggeredByDate,
                'triggered_by_reading' => $triggeredByReading,
            ]);

            return $mr;
        });
    }
}
