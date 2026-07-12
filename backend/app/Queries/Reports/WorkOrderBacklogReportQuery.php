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

        // Summary via SQL aggregation: bucket counts use datetime cutoffs
        // matching AgingBuckets::daysFrom (diffInDays truncates, so the
        // boundary between 0-7 and 8-30 is at $today - 8 days 00:00:00).
        // Priority counts use a separate GROUP BY query.
        $cutoff8 = $today->copy()->subDays(8);
        $cutoff31 = $today->copy()->subDays(31);
        $cutoff91 = $today->copy()->subDays(91);

        $summaryRow = (clone $base)->selectRaw('
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN created_at > ? THEN 1 ELSE 0 END), 0) as bucket_0_7,
            COALESCE(SUM(CASE WHEN created_at <= ? AND created_at > ? THEN 1 ELSE 0 END), 0) as bucket_8_30,
            COALESCE(SUM(CASE WHEN created_at <= ? AND created_at > ? THEN 1 ELSE 0 END), 0) as bucket_31_90,
            COALESCE(SUM(CASE WHEN created_at <= ? THEN 1 ELSE 0 END), 0) as bucket_91_plus
        ', [
            $cutoff8, $cutoff8, $cutoff31, $cutoff31, $cutoff91, $cutoff91,
        ])->first();

        $priorityRows = (clone $base)
            ->selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->get();

        $byPriority = [];
        foreach ($priorityRows as $row) {
            $byPriority[$row->priority] = (int) $row->count;
        }

        $summary = [
            'total' => (int) ($summaryRow->total ?? 0),
            'by_bucket' => [
                '0-7' => (int) ($summaryRow->bucket_0_7 ?? 0),
                '8-30' => (int) ($summaryRow->bucket_8_30 ?? 0),
                '31-90' => (int) ($summaryRow->bucket_31_90 ?? 0),
                '91+' => (int) ($summaryRow->bucket_91_plus ?? 0),
            ],
            'by_priority' => $byPriority,
        ];

        $paginator = (clone $base)
            ->with(['asset.currentLocation', 'assignedTo', 'assignedBy', 'maintenanceRequest'])
            ->withCount('attachments')
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate($perPage);

        return ['summary' => $summary, 'paginator' => $paginator];
    }
}
