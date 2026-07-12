<?php

namespace App\Queries\Reports;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Carbon\Carbon;

/**
 * R-4: MTTR (Mean Time To Repair) by dimension.
 *
 * Reuses the ReliabilityKpiQuery definition: MTTR = mean assigned_at → closed_at
 * duration of corrective work orders closed within the window. Only corrective
 * WOs (is_preventive = false on the linked MR) that are CLOSED with both
 * assigned_at and closed_at set are included. Groups by asset, category
 * (fa_subclass_code), or technician.
 */
class MttrReportQuery
{
    /**
     * @param  array{location_id?: ?int, fa_subclass_code?: ?string, technician_id?: ?int}  $filters
     * @return array{summary: array{mttr_hours: float|null, repair_count: int}, items: array<int, array{group_key: mixed, group_label: ?string, repair_count: int, mttr_hours: float|null}>}
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
            ->with(['asset.currentLocation', 'assignedTo'])
            ->get(['id', 'asset_id', 'assigned_to_user_id', 'assigned_at', 'closed_at']);

        $repairCount = $orders->count();
        $mttrHours = $this->meanHours($orders);

        // Group by dimension
        $grouped = $orders->groupBy(function ($wo) use ($groupBy) {
            return match ($groupBy) {
                'asset' => $wo->asset_id,
                'category' => $wo->asset?->fa_subclass_code ?? 'unknown',
                'technician' => $wo->assigned_to_user_id,
                default => $wo->asset_id,
            };
        });

        $items = $grouped->map(function ($groupOrders, $key) use ($groupBy) {
            $count = $groupOrders->count();
            $mttr = $this->meanHours($groupOrders);

            $first = $groupOrders->first();
            $label = match ($groupBy) {
                'asset' => $first->asset?->name,
                'category' => $key,
                'technician' => $first->assignedTo?->name,
                default => $key,
            };

            return [
                'group_key' => $key,
                'group_label' => $label,
                'repair_count' => $count,
                'mttr_hours' => $mttr,
            ];
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
