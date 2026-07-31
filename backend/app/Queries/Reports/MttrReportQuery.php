<?php

namespace App\Queries\Reports;

use App\Enums\WorkOrderStatus;
use App\Http\Resources\AssetIdentityResource;
use App\Models\WorkOrder;
use Carbon\Carbon;

/**
 * R-4: MTTR (Mean Time To Repair) by dimension.
 *
 * Reuses the ReliabilityKpiQuery definition: MTTR = mean assigned_at → closed_at
 * duration of corrective work orders closed within the window. Only corrective
 * WOs (is_preventive = false on the linked MR) that are CLOSED with both
 * assigned_at and closed_at set are included. Groups by asset, Maintenance
 * Category, Asset Class, Size, or technician through the shared
 * {@see AssetReportDimension} resolver.
 */
class MttrReportQuery
{
    /**
     * @param  array{location_id?: ?int, fa_subclass_code?: ?string, technician_id?: ?int}  $filters
     * @return array{summary: array{mttr_hours: float|null, repair_count: int}, items: array<int, array{group_key: mixed, group_label: ?string, repair_count: int, mttr_hours: float|null, asset?: array<string, mixed>}>}
     */
    public function handle(Carbon $from, Carbon $to, string $groupBy, array $filters): array
    {
        $orders = WorkOrder::whereHas('maintenanceRequest', fn ($q) => $q->where('is_preventive', false))
            ->where('status', WorkOrderStatus::CLOSED)
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from, $to])
            ->whereNotNull('assigned_at')
            ->when($filters['location_id'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
            ->when($filters['fa_subclass_code'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('fa_subclass_code', $v)))
            ->when($filters['technician_id'] ?? null, fn ($q, $v) =>
                $q->where('assigned_to_user_id', $v))
            ->with(['asset.currentLocation', 'asset.maintenanceCategory', 'assignedTo'])
            ->get(['id', 'asset_id', 'assigned_to_user_id', 'assigned_at', 'closed_at']);

        $repairCount = $orders->count();
        $mttrHours = $this->meanHours($orders);

        $dimension = new AssetReportDimension;

        // Group by dimension
        $grouped = $orders->groupBy(function ($wo) use ($groupBy, $dimension) {
            if ($groupBy === 'technician') {
                return $wo->assigned_to_user_id;
            }

            return $wo->asset
                ? $dimension->resolve($wo->asset, $groupBy)['key']
                : match ($groupBy) {
                    'asset' => $wo->asset_id,
                    'maintenance_category' => 'uncategorised',
                    'asset_class' => 'unclassified',
                    'size' => 'unspecified',
                    default => $wo->asset_id,
                };
        });

        $items = $grouped->map(function ($groupOrders, $key) use ($groupBy, $dimension) {
            $count = $groupOrders->count();
            $mttr = $this->meanHours($groupOrders);

            $first = $groupOrders->first();

            $item = [
                'group_key' => $key,
                'group_label' => match (true) {
                    $groupBy === 'technician' => $first->assignedTo?->name,
                    $first->asset !== null => $dimension->resolve($first->asset, $groupBy)['label'],
                    default => match ($groupBy) {
                        'maintenance_category' => 'Uncategorised',
                        'asset_class' => 'Unclassified',
                        'size' => 'Unspecified',
                        default => null,
                    },
                },
                'repair_count' => $count,
                'mttr_hours' => $mttr,
            ];

            if ($groupBy === 'asset' && $first->asset) {
                $item['asset'] = (new AssetIdentityResource($first->asset))->resolve();
            }

            return $item;
        })->sortByDesc('repair_count')->values();

        return [
            'summary' => [
                'mttr_hours' => $mttrHours,
                'repair_count' => $repairCount,
            ],
            'items' => $items->all(),
        ];
    }

    private function meanHours($orders): ?float
    {
        $hours = $orders
            ->map(fn (WorkOrder $wo) => $this->hoursBetween($wo->assigned_at, $wo->closed_at))
            ->filter(fn ($h) => $h !== null)
            ->values();

        return $hours->isEmpty() ? null : round($hours->avg(), 2);
    }

    private function hoursBetween(Carbon $start, ?Carbon $end): ?float
    {
        if (! $end) {
            return null;
        }

        return ($end->getTimestamp() - $start->getTimestamp()) / 3600;
    }
}
