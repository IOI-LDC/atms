<?php

namespace App\Queries\Reports;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;

/**
 * R-15: Technician Workload Report
 *
 * Shows workload metrics per technician within a date window:
 * - Count of assigned WOs (all statuses)
 * - Count of open WOs
 * - Count of in-progress WOs
 * - Count of completed WOs
 * - Count of cancelled WOs
 *
 * Operational workload only - no productivity/labor metrics per D-2.
 * Paginated via DB-level GROUP BY cursor pagination.
 */
class TechnicianWorkloadReportQuery
{
    /**
     * @param  array{technician_id?: ?int}  $filters
     * @return array{summary: array<string, int>, paginator: CursorPaginator}
     */
    public function handle(int $perPage, Carbon $from, Carbon $to, array $filters): array
    {
        $now = now();
        $base = WorkOrder::query()
            ->whereNotNull('work_orders.assigned_to_user_id')
            ->whereBetween('work_orders.created_at', [$from, $to])
            ->when($filters['technician_id'] ?? null, fn ($query, $technicianId) =>
                $query->where('work_orders.assigned_to_user_id', $technicianId));

        $summaryRow = (clone $base)
            ->selectRaw('COUNT(*) as total_work_orders')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as total_open', [WorkOrderStatus::OPEN->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as total_in_progress', [WorkOrderStatus::IN_PROGRESS->value])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as total_completed', [
                WorkOrderStatus::COMPLETED->value,
                WorkOrderStatus::CLOSED->value,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as total_cancelled', [WorkOrderStatus::CANCELLED->value])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as total_backlog', [
                WorkOrderStatus::OPEN->value,
                WorkOrderStatus::IN_PROGRESS->value,
            ])
            ->selectRaw('AVG(CASE WHEN status IN (?, ?) AND started_at IS NOT NULL AND completed_at IS NOT NULL THEN EXTRACT(EPOCH FROM (completed_at - started_at)) / 3600.0 END) as avg_duration_hours', [
                WorkOrderStatus::COMPLETED->value,
                WorkOrderStatus::CLOSED->value,
            ])
            ->selectRaw('AVG(CASE WHEN status IN (?, ?) THEN GREATEST(0, EXTRACT(EPOCH FROM (?::timestamp - created_at)) / 86400.0) END) as avg_backlog_age_days', [
                WorkOrderStatus::OPEN->value,
                WorkOrderStatus::IN_PROGRESS->value,
                $now->toDateTimeString(),
            ])
            ->first();

        $summary = [
            'total_work_orders' => (int) ($summaryRow->total_work_orders ?? 0),
            'total_assigned' => (int) ($summaryRow->total_work_orders ?? 0),
            'total_open' => (int) ($summaryRow->total_open ?? 0),
            'total_in_progress' => (int) ($summaryRow->total_in_progress ?? 0),
            'total_completed' => (int) ($summaryRow->total_completed ?? 0),
            'total_cancelled' => (int) ($summaryRow->total_cancelled ?? 0),
            'total_backlog' => (int) ($summaryRow->total_backlog ?? 0),
            'avg_duration_hours' => $summaryRow?->avg_duration_hours !== null
                ? round((float) $summaryRow->avg_duration_hours, 2)
                : null,
            'avg_backlog_age_days' => $summaryRow?->avg_backlog_age_days !== null
                ? round((float) $summaryRow->avg_backlog_age_days, 1)
                : null,
        ];

        $grouped = (clone $base)
            ->leftJoin('users', 'users.id', '=', 'work_orders.assigned_to_user_id')
            ->selectRaw('work_orders.assigned_to_user_id as technician_id')
            ->selectRaw('users.name as technician_name')
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open_count', [WorkOrderStatus::OPEN->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as in_progress_count', [WorkOrderStatus::IN_PROGRESS->value])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as completed_count', [
                WorkOrderStatus::COMPLETED->value,
                WorkOrderStatus::CLOSED->value,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_count', [WorkOrderStatus::CANCELLED->value])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as backlog_count', [
                WorkOrderStatus::OPEN->value,
                WorkOrderStatus::IN_PROGRESS->value,
            ])
            ->selectRaw('AVG(CASE WHEN status IN (?, ?) AND started_at IS NOT NULL AND completed_at IS NOT NULL THEN EXTRACT(EPOCH FROM (completed_at - started_at)) / 3600.0 END) as avg_duration_hours', [
                WorkOrderStatus::COMPLETED->value,
                WorkOrderStatus::CLOSED->value,
            ])
            ->selectRaw('AVG(CASE WHEN status IN (?, ?) THEN GREATEST(0, EXTRACT(EPOCH FROM (?::timestamp - work_orders.created_at)) / 86400.0) END) as avg_backlog_age_days', [
                WorkOrderStatus::OPEN->value,
                WorkOrderStatus::IN_PROGRESS->value,
                $now->toDateTimeString(),
            ])
            ->groupBy('work_orders.assigned_to_user_id', 'users.name');

        $paginator = DB::query()
            ->fromSub($grouped, 'workload')
            ->orderByDesc('total_count')
            ->orderBy('technician_id')
            ->cursorPaginate($perPage)
            ->through(fn ($row) => [
                'technician_id' => (int) $row->technician_id,
                'technician_name' => $row->technician_name,
                'total_count' => (int) $row->total_count,
                'open_count' => (int) $row->open_count,
                'in_progress_count' => (int) $row->in_progress_count,
                'completed_count' => (int) $row->completed_count,
                'cancelled_count' => (int) $row->cancelled_count,
                'backlog_count' => (int) $row->backlog_count,
                'avg_duration_hours' => $row->avg_duration_hours !== null
                    ? round((float) $row->avg_duration_hours, 2)
                    : null,
                'avg_backlog_age_days' => $row->avg_backlog_age_days !== null
                    ? round((float) $row->avg_backlog_age_days, 1)
                    : null,
            ]);

        return [
            'summary' => $summary,
            'paginator' => $paginator,
        ];
    }
}
