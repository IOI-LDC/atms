<?php

namespace App\Queries\Pm;

use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\PmOccurrenceSuppression;
use App\Services\Pm\PmDueCalculator;
use Illuminate\Support\Collection;

class OverduePmQuery
{
    public function __construct(private PmDueCalculator $calculator) {}

    public function execute(int $limit = 5): Collection
    {
        $assignments = AssetPmAssignment::where('is_active', true)
            ->whereHas('pmRule', fn ($q) => $q->where('is_active', true))
            ->with(['asset', 'pmRule.usageReadingType'])
            ->get();

        $readings = $this->loadLatestConfirmedReadings($assignments);
        $suppressions = $this->loadActiveSuppressions($assignments);

        return $assignments
            ->filter(fn ($assignment) => $this->calculator->isDue($assignment, $readings, $suppressions))
            ->take($limit)
            ->values();
    }

    private function loadLatestConfirmedReadings(Collection $assignments): Collection
    {
        $readingTypeIds = $assignments
            ->map(fn ($a) => $a->pmRule?->usage_reading_type_id)
            ->filter()
            ->unique();

        if ($readingTypeIds->isEmpty()) {
            return collect();
        }

        return AssetMeterReading::whereIn('asset_id', $assignments->pluck('asset_id')->unique())
            ->whereIn('usage_reading_type_id', $readingTypeIds)
            ->whereNotNull('confirmed_at')
            ->get()
            ->groupBy(fn ($r) => "{$r->asset_id}_{$r->usage_reading_type_id}")
            ->map(fn ($group) => $group->sortByDesc('reading_at')->first());
    }

    private function loadActiveSuppressions(Collection $assignments): Collection
    {
        $all = PmOccurrenceSuppression::whereIn('pm_rule_id', $assignments->pluck('pm_rule_id')->unique())->get();
        $grouped = collect();

        foreach ($assignments as $assignment) {
            foreach ($all as $suppression) {
                if ($suppression->pm_rule_id !== $assignment->pm_rule_id || $suppression->asset_id !== $assignment->asset_id) {
                    continue;
                }

                if ($suppression->triggered_by_date && $suppression->suppressed_until_date >= now()->toDateString()) {
                    $key = "{$assignment->id}_date";
                    $grouped[$key] = $grouped->get($key, collect())->push($suppression);
                }

                if ($suppression->triggered_by_reading) {
                    $key = "{$assignment->id}_reading";
                    $grouped[$key] = $grouped->get($key, collect())->push($suppression);
                }
            }
        }

        return $grouped;
    }
}
