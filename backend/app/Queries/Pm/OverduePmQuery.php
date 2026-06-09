<?php

namespace App\Queries\Pm;

use App\Models\AssetMeterReading;
use App\Models\PmOccurrenceSuppression;
use App\Models\PmRule;
use App\Services\Pm\PmDueCalculator;
use Illuminate\Support\Collection;

class OverduePmQuery
{
    public function __construct(private PmDueCalculator $calculator) {}

    public function execute(int $limit = 5): Collection
    {
        $rules = PmRule::where('is_active', true)
            ->with('asset')
            ->get();

        $readings = $this->loadLatestConfirmedReadings($rules);
        $suppressions = $this->loadActiveSuppressions($rules);

        return $rules
            ->filter(fn ($rule) => $this->calculator->isDue($rule, $readings, $suppressions))
            ->take($limit)
            ->values();
    }

    private function loadLatestConfirmedReadings(Collection $rules): Collection
    {
        $readingTypeIds = $rules->pluck('usage_reading_type_id')->filter()->unique();

        if ($readingTypeIds->isEmpty()) {
            return collect();
        }

        return AssetMeterReading::whereIn('asset_id', $rules->pluck('asset_id')->unique())
            ->whereIn('usage_reading_type_id', $readingTypeIds)
            ->whereNotNull('confirmed_at')
            ->get()
            ->groupBy(fn ($r) => "{$r->asset_id}_{$r->usage_reading_type_id}")
            ->map(fn ($group) => $group->sortByDesc('reading_at')->first());
    }

    private function loadActiveSuppressions(Collection $rules): Collection
    {
        $all = PmOccurrenceSuppression::whereIn('pm_rule_id', $rules->pluck('id'))->get();
        $grouped = collect();

        foreach ($all as $s) {
            if ($s->triggered_by_date && $s->suppressed_until_date >= now()->toDateString()) {
                $key = $s->pm_rule_id.'_date';
                $grouped[$key] = $grouped->get($key, collect())->push($s);
            }
            if ($s->triggered_by_reading) {
                $key = $s->pm_rule_id.'_reading';
                $grouped[$key] = $grouped->get($key, collect())->push($s);
            }
        }

        return $grouped;
    }
}
