<?php

namespace App\Queries\Reports;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceRequest;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * R-8: overdue date-triggered PM requests, paginated with aging buckets.
 *
 * Overdue = PM MR (is_preventive + triggered_by_date) whose trigger_date is
 * past, status is not terminal (rejected/cancelled), and the linked work order
 * is absent or not yet CLOSED. The `bucket` row filter narrows only the
 * paginated rows; summary.by_bucket is facet context over the full scoped set
 * (D8). Order is deterministic: trigger_date then id (cursor tie-breaker).
 */
class OverduePmReportQuery
{
    /**
     * @param  array{location_id?: ?int, pm_rule_id?: ?int, priority?: ?string, bucket?: ?string}  $filters
     * @return array{summary: array{total: int, by_bucket: array<string, int>}, paginator: CursorPaginator}
     */
    public function handle(int $perPage, array $filters): array
    {
        $today = now()->startOfDay();

        $base = MaintenanceRequest::where('is_preventive', true)
            ->where('triggered_by_date', true)
            ->where('trigger_date', '<', $today->toDateString())
            ->whereNotIn('status', [MaintenanceRequestStatus::REJECTED, MaintenanceRequestStatus::CANCELLED])
            ->where(function ($q) {
                $q->doesntHave('workOrder')
                    ->orWhereHas('workOrder', fn ($wq) => $wq->where('status', '!=', WorkOrderStatus::CLOSED));
            })
            ->when($filters['location_id'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
            ->when($filters['pm_rule_id'] ?? null, fn ($q, $v) => $q->where('pm_rule_id', $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v));

        // Summary = facet context (D8): bucket counts over the FULL scoped set
        // (location/priority/pm_rule filters applied, NOT the `bucket` row
        // filter). Always returns all 4 buckets; summary.total is the scoped
        // grand total, not the filtered-bucket count.
        $summaryRows = (clone $base)->select('trigger_date')->get();
        $perBucket = array_fill_keys(AgingBuckets::BUCKETS, 0);
        foreach ($summaryRows as $mr) {
            $perBucket[AgingBuckets::bucket(AgingBuckets::daysFrom($today, $mr->trigger_date))]++;
        }
        $summary = ['total' => $summaryRows->count(), 'by_bucket' => $perBucket];

        // Rows: apply the optional bucket filter, then paginate deterministically.
        $rowsQuery = clone $base;
        if ($filters['bucket'] ?? null) {
            [$lower, $upper] = AgingBuckets::dateBounds($filters['bucket'], $today);
            $rowsQuery->when($lower, fn ($q, $v) => $q->where('trigger_date', '>=', $v->toDateString()))
                ->when($upper, fn ($q, $v) => $q->where('trigger_date', '<=', $v->toDateString()));
        }

        $paginator = $rowsQuery
            ->with(['asset.currentLocation', 'pmRule', 'workOrder', 'createdBy', 'reviewedBy', 'attachments'])
            ->orderBy('trigger_date')
            ->orderBy('id')
            ->cursorPaginate($perPage);

        return ['summary' => $summary, 'paginator' => $paginator];
    }
}
