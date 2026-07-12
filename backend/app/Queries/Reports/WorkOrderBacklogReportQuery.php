<?php

namespace App\Queries\Reports;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * R-14: open/in-progress work-order backlog with aging buckets. `age_days`
 * is full days since created_at (today start-of-day minus created_at, abs'd
 * via AgingBuckets::daysFrom to defeat Carbon 3's signed diffInDays). Order
 * is deterministic: created_at then id (cursor tie-breaker). Parts/form are
 * intentionally not eager-loaded — they are detail-page fields, not list fields.
 */
class WorkOrderBacklogReportQuery
{
    /**
     * @param  array{location_id?: ?int, assigned_to?: ?int, priority?: ?string, status?: ?string}  $filters
     * @return array{summary: array{total: int, by_bucket: array<string, int>, by_priority: array<string, int>}, paginator: CursorPaginator}
     */
    public function handle(int $perPage, array $filters): array
    {
        $statuses = match ($filters['status'] ?? 'both') {
            'open' => [WorkOrderStatus::OPEN],
            'in_progress' => [WorkOrderStatus::IN_PROGRESS],
            default => [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS],
        };

        $base = WorkOrder::whereIn('status', $statuses)
            ->when($filters['location_id'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
            ->when($filters['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to_user_id', $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v));

        $today = now()->startOfDay();
        $summaryRows = (clone $base)->select('created_at', 'priority')->get();
        $perBucket = array_fill_keys(AgingBuckets::BUCKETS, 0);
        $byPriority = [];
        foreach ($summaryRows as $wo) {
            $perBucket[AgingBuckets::bucket(AgingBuckets::daysFrom($today, $wo->created_at))]++;
            $byPriority[$wo->priority] = ($byPriority[$wo->priority] ?? 0) + 1;
        }
        $summary = [
            'total' => $summaryRows->count(),
            'by_bucket' => $perBucket,
            'by_priority' => $byPriority,
        ];

        $paginator = (clone $base)
            ->with(['asset.currentLocation', 'assignedTo', 'assignedBy', 'attachments', 'maintenanceRequest'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate($perPage);

        return ['summary' => $summary, 'paginator' => $paginator];
    }
}
