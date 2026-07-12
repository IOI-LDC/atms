<?php

namespace App\Queries\Reports;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * R-15: Technician Workload Report
 *
 * Shows workload metrics per technician within a date window:
 * - Count of assigned WOs (all statuses)
 * - Count of open WOs
 * - Count of in-progress WOs
 * - Count of completed WOs
 * - Count of cancelled WOs
 * - Average duration (started_at to completed_at) for completed WOs
 *
 * Operational workload only - no productivity/labor metrics per D-2.
 */
class TechnicianWorkloadReportQuery
{
    public function handle(Carbon $from, Carbon $to): array
    {
        $workOrders = WorkOrder::whereNotNull('assigned_to_user_id')
            ->whereBetween('created_at', [$from, $to])
            ->with('assignedTo')
            ->get();

        $summary = [
            'total_work_orders' => $workOrders->count(),
            'total_open' => $workOrders->where('status', WorkOrderStatus::OPEN)->count(),
            'total_in_progress' => $workOrders->where('status', WorkOrderStatus::IN_PROGRESS)->count(),
            'total_completed' => $workOrders->where('status', WorkOrderStatus::COMPLETED)->count(),
            'total_cancelled' => $workOrders->where('status', WorkOrderStatus::CANCELLED)->count(),
        ];

        $grouped = $workOrders->groupBy('assigned_to_user_id');

        $items = $grouped->map(function (Collection $techWorkOrders, int $techId) {
            $tech = $techWorkOrders->first()->assignedTo;
            $completed = $techWorkOrders->where('status', WorkOrderStatus::COMPLETED);

            $avgDuration = null;
            if ($completed->isNotEmpty()) {
                $durations = $completed
                    ->filter(fn ($wo) => $wo->started_at && $wo->completed_at)
                    ->map(fn ($wo) => $wo->started_at->diffInHours($wo->completed_at))
                    ->values();

                if ($durations->isNotEmpty()) {
                    $avgDuration = round($durations->avg(), 2);
                }
            }

            return [
                'technician_id' => $techId,
                'technician_name' => $tech->name,
                'total_count' => $techWorkOrders->count(),
                'open_count' => $techWorkOrders->where('status', WorkOrderStatus::OPEN)->count(),
                'in_progress_count' => $techWorkOrders->where('status', WorkOrderStatus::IN_PROGRESS)->count(),
                'completed_count' => $techWorkOrders->where('status', WorkOrderStatus::COMPLETED)->count(),
                'cancelled_count' => $techWorkOrders->where('status', WorkOrderStatus::CANCELLED)->count(),
                'avg_duration_hours' => $avgDuration,
            ];
        })->sortByDesc('total_count')->values()->all();

        return [
            'summary' => $summary,
            'items' => $items,
        ];
    }
}
