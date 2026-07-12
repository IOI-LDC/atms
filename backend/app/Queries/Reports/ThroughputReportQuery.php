<?php

namespace App\Queries\Reports;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * R-16: Work Order Throughput Report
 *
 * Shows work order creation and completion metrics within a date window:
 * - Count of WOs by status (open, in-progress, completed, closed, cancelled)
 * - Daily breakdown showing WO counts per day
 *
 * Operational throughput only - no labor/efficiency metrics per D-2.
 */
class ThroughputReportQuery
{
    public function handle(Carbon $from, Carbon $to): array
    {
        $workOrders = WorkOrder::whereBetween('created_at', [$from, $to])
            ->get();

        $summary = [
            'total_work_orders' => $workOrders->count(),
            'open_count' => $workOrders->where('status', WorkOrderStatus::OPEN)->count(),
            'in_progress_count' => $workOrders->where('status', WorkOrderStatus::IN_PROGRESS)->count(),
            'completed_count' => $workOrders->where('status', WorkOrderStatus::COMPLETED)->count(),
            'closed_count' => $workOrders->where('status', WorkOrderStatus::CLOSED)->count(),
            'cancelled_count' => $workOrders->where('status', WorkOrderStatus::CANCELLED)->count(),
        ];

        // Group by date
        $grouped = $workOrders->groupBy(fn ($wo) => $wo->created_at->toDateString());

        $items = $grouped->map(function (Collection $dayWorkOrders, string $date) {
            return [
                'date' => $date,
                'total_count' => $dayWorkOrders->count(),
                'open_count' => $dayWorkOrders->where('status', WorkOrderStatus::OPEN)->count(),
                'in_progress_count' => $dayWorkOrders->where('status', WorkOrderStatus::IN_PROGRESS)->count(),
                'completed_count' => $dayWorkOrders->where('status', WorkOrderStatus::COMPLETED)->count(),
                'closed_count' => $dayWorkOrders->where('status', WorkOrderStatus::CLOSED)->count(),
                'cancelled_count' => $dayWorkOrders->where('status', WorkOrderStatus::CANCELLED)->count(),
            ];
        })->sortByDesc('date')->values()->all();

        return [
            'summary' => $summary,
            'items' => $items,
        ];
    }
}
