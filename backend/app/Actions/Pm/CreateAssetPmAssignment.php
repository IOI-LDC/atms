<?php

namespace App\Actions\Pm;

use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\PmRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateAssetPmAssignment
{
    public function execute(Asset $asset, PmRule $rule, int $assignedByUserId): AssetPmAssignment
    {
        return DB::transaction(function () use ($asset, $rule, $assignedByUserId) {
            // Initial baseline: one full grace interval before the first PM is due.
            $lastTriggeredDate = now()->toDateString();
            $lastTriggeredReading = null;

            if ($rule->usage_reading_type_id) {
                $lastTriggeredReading = AssetMeterReading::where('asset_id', $asset->id)
                    ->where('usage_reading_type_id', $rule->usage_reading_type_id)
                    ->whereNotNull('confirmed_at')
                    ->orderByDesc('reading_at')
                    ->value('reading_value');
            }

            $created = AssetPmAssignment::create([
                'asset_id' => $asset->id,
                'pm_rule_id' => $rule->id,
                'last_triggered_date' => $lastTriggeredDate,
                'last_triggered_reading' => $lastTriggeredReading,
                'is_active' => true,
                'assigned_by' => $assignedByUserId,
            ]);

            $created->load(['asset', 'pmRule.usageReadingType', 'assignedBy']);
            app(AuditLogger::class)->log('pm_assignment.created', $created, [], $created->toArray());

            return $created;
        });
    }
}
