<?php

namespace App\Queries\Dashboard\Kpis;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use Carbon\Carbon;

/**
 * Process-performance KPIs over a rolling calendar window.
 *
 * - PM Compliance: date-triggered PM requests due within the window. A PM is
 *   on-time when its linked work order closed on or before the trigger date.
 *   Reading-triggered PMs are excluded (no clean calendar due-date).
 * - Avg MR Duration: created_at -> resolution timestamp for requests resolved
 *   in-window (reviewed_at for converted/rejected, cancelled_at for cancelled).
 * - Avg WO Duration: created_at -> closed_at for work orders closed in-window.
 */
class ProcessPerformanceKpiQuery
{
    /**
     * @return array{
     *     pm_compliance: array{compliant: int, total: int, percentage: float|null},
     *     avg_mr_duration: array{hours: float|null},
     *     avg_wo_duration: array{hours: float|null},
     * }
     */
    public function handle(Carbon $since, Carbon $now): array
    {
        return [
            'pm_compliance' => $this->pmCompliance($since, $now),
            'avg_mr_duration' => ['hours' => $this->meanMrDurationHours($since, $now)],
            'avg_wo_duration' => ['hours' => $this->meanWoDurationHours($since, $now)],
        ];
    }

    /**
     * @return array{compliant: int, total: int, percentage: float|null}
     */
    private function pmCompliance(Carbon $since, Carbon $now): array
    {
        $due = MaintenanceRequest::where('is_preventive', true)
            ->where('triggered_by_date', true)
            ->whereBetween('trigger_date', [$since->toDateString(), $now->toDateString()])
            ->with('workOrder')
            ->get(['id', 'trigger_date']);

        $compliant = $due->filter(function (MaintenanceRequest $mr): bool {
            $wo = $mr->workOrder;

            return $wo !== null
                && $wo->status === WorkOrderStatus::CLOSED
                && $wo->closed_at !== null
                && $wo->closed_at->toDateString() <= $mr->trigger_date->toDateString();
        })->count();

        $total = $due->count();

        return [
            'compliant' => $compliant,
            'total' => $total,
            'percentage' => $total > 0 ? round($compliant / $total * 100, 1) : null,
        ];
    }

    private function meanMrDurationHours(Carbon $since, Carbon $now): ?float
    {
        $resolved = MaintenanceRequest::whereIn('status', [
            MaintenanceRequestStatus::CONVERTED,
            MaintenanceRequestStatus::REJECTED,
            MaintenanceRequestStatus::CANCELLED,
        ])
            ->where(function ($q) use ($since, $now): void {
                $q->whereBetween('reviewed_at', [$since, $now])
                    ->orWhereBetween('cancelled_at', [$since, $now]);
            })
            ->get(['created_at', 'reviewed_at', 'cancelled_at']);

        $hours = $resolved
            ->map(function (MaintenanceRequest $mr): ?float {
                $terminal = $mr->reviewed_at ?? $mr->cancelled_at;

                return $terminal !== null
                    ? ($terminal->getTimestamp() - $mr->created_at->getTimestamp()) / 3600
                    : null;
            })
            ->filter(fn ($h) => $h !== null)
            ->values();

        return $hours->isEmpty() ? null : round($hours->avg(), 2);
    }

    private function meanWoDurationHours(Carbon $since, Carbon $now): ?float
    {
        $orders = WorkOrder::where('status', WorkOrderStatus::CLOSED)
            ->whereBetween('closed_at', [$since, $now])
            ->get(['created_at', 'closed_at']);

        $hours = $orders
            ->map(fn (WorkOrder $wo) => $this->hoursBetween($wo->created_at, $wo->closed_at))
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
