<?php

namespace App\Queries\Reports;

use App\Models\MaintenanceRequest;
use Carbon\Carbon;

/**
 * R-6: Bad-Actor / Breakdown Analysis.
 *
 * Identifies assets, categories, or locations with the most confirmed failures
 * (is_failure = true) within a date window. Sorted by failure_count descending.
 * ATMS has no failure taxonomy — this report identifies bad actors by count
 * only, not by failure mode or root cause.
 */
class BadActorReportQuery
{
    /**
     * @param  array{location_id?: ?int, fa_subclass_code?: ?string, limit?: ?int}  $filters
     * @return array{summary: array{total_failures: int}, items: array<int, array{group_key: mixed, group_label: ?string, failure_count: int}>}
     */
    public function handle(Carbon $from, Carbon $to, string $groupBy, array $filters): array
    {
        $failures = MaintenanceRequest::where('is_failure', true)
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['location_id'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
            ->when($filters['fa_subclass_code'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('fa_subclass_code', $v)))
            ->with(['asset.currentLocation'])
            ->get();

        $totalFailures = $failures->count();

        // Group by dimension
        $grouped = $failures->groupBy(function ($mr) use ($groupBy) {
            return match ($groupBy) {
                'asset' => $mr->asset_id,
                'category' => $mr->asset?->fa_subclass_code ?? 'unknown',
                'location' => $mr->asset?->current_location_id ?? 'unassigned',
                default => $mr->asset_id,
            };
        });

        $items = $grouped->map(function ($groupFailures, $key) use ($groupBy) {
            $count = $groupFailures->count();

            $first = $groupFailures->first();
            $label = match ($groupBy) {
                'asset' => $first->asset?->name,
                'category' => $key,
                'location' => $first->asset?->currentLocation?->name ?? 'Unassigned',
                default => $key,
            };

            return [
                'group_key' => $key,
                'group_label' => $label,
                'failure_count' => $count,
            ];
        })->sortByDesc('failure_count')->values();

        // Apply limit if specified
        $limit = $filters['limit'] ?? null;
        if ($limit !== null && $limit > 0) {
            $items = $items->take($limit);
        }

        return [
            'summary' => [
                'total_failures' => $totalFailures,
            ],
            'items' => $items->values()->all(),
        ];
    }
}
