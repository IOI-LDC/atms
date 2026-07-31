<?php

namespace App\Queries\Reports;

use App\Http\Resources\AssetIdentityResource;
use App\Models\MaintenanceRequest;
use Carbon\Carbon;

/**
 * R-6: Bad-Actor / Breakdown Analysis.
 *
 * Identifies assets, categories, classes, sizes, or locations with the most
 * confirmed failures (is_failure = true) within a date window. Sorted by
 * failure_count descending. ATMS has no failure taxonomy — this report
 * identifies bad actors by count only, not by failure mode or root cause.
 */
class BadActorReportQuery
{
    /**
     * @param  array{location_id?: ?int, fa_subclass_code?: ?string, limit?: ?int}  $filters
     * @return array{summary: array{total_failures: int}, items: array<int, array{group_key: mixed, group_label: ?string, failure_count: int, asset?: array<string, mixed>}>}
     */
    public function handle(Carbon $from, Carbon $to, string $groupBy, array $filters): array
    {
        $failures = MaintenanceRequest::where('is_failure', true)
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['location_id'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
            ->when($filters['fa_subclass_code'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('fa_subclass_code', $v)))
            ->with(['asset.currentLocation', 'asset.maintenanceCategory'])
            ->get();

        $totalFailures = $failures->count();

        $dimension = new AssetReportDimension;

        // Group by dimension
        $grouped = $failures->groupBy(function ($mr) use ($groupBy, $dimension) {
            if ($groupBy === 'location') {
                return $mr->asset?->current_location_id ?? 'unassigned';
            }

            return $mr->asset
                ? $dimension->resolve($mr->asset, $groupBy)['key']
                : match ($groupBy) {
                    'asset' => $mr->asset_id,
                    'maintenance_category' => 'uncategorised',
                    'asset_class' => 'unclassified',
                    'size' => 'unspecified',
                    default => $mr->asset_id,
                };
        });

        $items = $grouped->map(function ($groupFailures, $key) use ($groupBy, $dimension) {
            $count = $groupFailures->count();

            $first = $groupFailures->first();

            $item = [
                'group_key' => $key,
                'group_label' => match (true) {
                    $groupBy === 'location' => $first->asset?->currentLocation?->name ?? 'Unassigned',
                    $first->asset !== null => $dimension->resolve($first->asset, $groupBy)['label'],
                    default => match ($groupBy) {
                        'maintenance_category' => 'Uncategorised',
                        'asset_class' => 'Unclassified',
                        'size' => 'Unspecified',
                        default => null,
                    },
                },
                'failure_count' => $count,
            ];

            if ($groupBy === 'asset' && $first->asset) {
                $item['asset'] = (new AssetIdentityResource($first->asset))->resolve();
            }

            return $item;
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
