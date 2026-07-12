<?php

namespace App\Queries\Reports;

use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * R-7: PM compliance over a calendar window, grouped by rule/asset/location.
 * Reuses the exact on-time rule from ProcessPerformanceKpiQuery::pmCompliance:
 * a date-triggered PM MR is compliant when its linked work order is CLOSED and
 * closed on or before the trigger date. Reading-triggered PMs are excluded
 * from the denominator (no clean calendar due-date).
 */
class PmComplianceReportQuery
{
    /**
     * @param  array{location_id?: ?int, pm_rule_id?: ?int}  $filters
     * @return array{summary: array{compliant: int, total: int, percentage: float|null}, items: array<int, array{group_key: mixed, group_label: ?string, compliant: int, total: int, percentage: float|null}>}
     */
    public function handle(Carbon $from, Carbon $to, string $groupBy, array $filters): array
    {
        $due = MaintenanceRequest::where('is_preventive', true)
            ->where('triggered_by_date', true)
            ->whereBetween('trigger_date', [$from->toDateString(), $to->toDateString()])
            ->when($filters['pm_rule_id'] ?? null, fn ($q, $v) => $q->where('pm_rule_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
            ->with(['workOrder', 'pmRule', 'asset.currentLocation'])
            ->get();

        $isCompliant = fn (MaintenanceRequest $mr): bool => $mr->workOrder !== null
            && $mr->workOrder->status === WorkOrderStatus::CLOSED
            && $mr->workOrder->closed_at !== null
            && $mr->workOrder->closed_at->toDateString() <= $mr->trigger_date->toDateString();

        [$keyResolver, $labelResolver] = $this->groupResolvers($groupBy);

        $items = $due->groupBy($keyResolver)->map(function (Collection $mrs, $key) use ($isCompliant, $labelResolver) {
            $total = $mrs->count();
            $compliant = $mrs->filter($isCompliant)->count();

            return [
                'group_key' => $key,
                'group_label' => $labelResolver($key, $mrs->first()),
                'compliant' => $compliant,
                'total' => $total,
                'percentage' => $total > 0 ? round($compliant / $total * 100, 1) : null,
            ];
        })->sortBy(fn ($item) => [$item['group_label'] === null, $item['group_label'] ?? ''])->values();

        $total = $due->count();
        $compliant = $due->filter($isCompliant)->count();

        return [
            'summary' => [
                'compliant' => $compliant,
                'total' => $total,
                'percentage' => $total > 0 ? round($compliant / $total * 100, 1) : null,
            ],
            'items' => $items->all(),
        ];
    }

    /**
     * @return array{0: callable, 1: callable}
     */
    private function groupResolvers(string $groupBy): array
    {
        return match ($groupBy) {
            'asset' => [
                fn (MaintenanceRequest $mr) => $mr->asset_id,
                fn ($key, MaintenanceRequest $mr) => $mr->asset?->name,
            ],
            'location' => [
                fn (MaintenanceRequest $mr) => $mr->asset?->current_location_id,
                fn ($key, MaintenanceRequest $mr) => $mr->asset?->currentLocation?->name,
            ],
            default => [
                fn (MaintenanceRequest $mr) => $mr->pm_rule_id,
                fn ($key, MaintenanceRequest $mr) => $mr->pmRule?->name,
            ],
        };
    }
}
