<?php

namespace App\Queries\Reports;

use App\Http\Resources\AssetIdentityResource;
use App\Models\MaintenanceRequest;
use Carbon\Carbon;

/**
 * R-3: MTBF (Mean Time Between Failures) by dimension.
 *
 * Reuses the ReliabilityKpiQuery definition: a "failure" is a corrective
 * maintenance request explicitly classified as is_failure = true. MTBF is
 * calculated as window_days / failure_count. Groups by asset, Maintenance
 * Category, Asset Class, Size, or location through the shared
 * {@see AssetReportDimension} resolver.
 */
class MtbfReportQuery
{
    /**
     * @param  array{location_id?: ?int, fa_subclass_code?: ?string}  $filters
     * @return array{summary: array{mtbf_days: float|null, failure_count: int, failure_rate_per_day: float}, items: array<int, array{group_key: mixed, group_label: ?string, failure_count: int, mtbf_days: float|null, failure_rate_per_day: float, asset?: array<string, mixed>}>}
     */
    public function handle(Carbon $from, Carbon $to, string $groupBy, array $filters): array
    {
        $windowDays = max(1, (int) abs($from->diffInDays($to)));

        $failures = MaintenanceRequest::where('is_failure', true)
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['location_id'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
            ->when($filters['fa_subclass_code'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('fa_subclass_code', $v)))
            ->with(['asset.currentLocation', 'asset.maintenanceCategory'])
            ->get();

        $failureCount = $failures->count();
        $mtbfDays = $failureCount > 0 ? round($windowDays / $failureCount, 2) : null;
        $failureRatePerDay = round($failureCount / $windowDays, 4);

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

        $items = $grouped->map(function ($groupFailures, $key) use ($windowDays, $groupBy, $dimension) {
            $count = $groupFailures->count();
            $mtbf = $count > 0 ? round($windowDays / $count, 2) : null;
            $rate = round($count / $windowDays, 4);

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
                'mtbf_days' => $mtbf,
                'failure_rate_per_day' => $rate,
            ];

            if ($groupBy === 'asset' && $first->asset) {
                $item['asset'] = (new AssetIdentityResource($first->asset))->resolve();
            }

            return $item;
        })->sortByDesc('failure_count')->values();

        return [
            'summary' => [
                'mtbf_days' => $mtbfDays,
                'failure_count' => $failureCount,
                'failure_rate_per_day' => $failureRatePerDay,
            ],
            'items' => $items->all(),
        ];
    }
}
