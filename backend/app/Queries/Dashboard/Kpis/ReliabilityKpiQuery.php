<?php

namespace App\Queries\Dashboard\Kpis;

use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use Carbon\Carbon;

/**
 * Reliability KPIs over a rolling calendar window.
 *
 * A "failure" is a corrective maintenance request explicitly classified as a
 * real failure (is_failure = true). Set by a manager at approval, optionally
 * revised at WO close. Unclassified (null) and no-failure-found (false) CMRs
 * are excluded.
 * - MTBF: calendar basis = window days / classified failures.
 * - Failure Rate: classified failures and failures-per-day within the window.
 * - MTTR: mean assigned_at -> closed_at duration of corrective work orders
 *   closed within the window (full repair cycle, not execution-only).
 */
class ReliabilityKpiQuery
{
    /**
     * @return array{
     *     mtbf: array{days: float|null},
     *     failure_rate: array{failures: int, per_day: float},
     *     mttr: array{hours: float|null},
     * }
     */
    public function handle(Carbon $since, Carbon $now, int $windowDays): array
    {
        $failures = $this->countCorrectiveFailures($since, $now);

        return [
            'mtbf' => [
                'days' => $failures > 0 ? round($windowDays / $failures, 2) : null,
            ],
            'failure_rate' => [
                'failures' => $failures,
                'per_day' => round($failures / $windowDays, 4),
            ],
            'mttr' => ['hours' => $this->meanCorrectiveRepairHours($since, $now)],
        ];
    }

    private function countCorrectiveFailures(Carbon $since, Carbon $now): int
    {
        return MaintenanceRequest::where('is_failure', true)
            ->whereBetween('created_at', [$since, $now])
            ->count();
    }

    private function meanCorrectiveRepairHours(Carbon $since, Carbon $now): ?float
    {
        $orders = WorkOrder::whereHas('maintenanceRequest', fn ($q) => $q->where('is_preventive', false))
            ->where('status', WorkOrderStatus::CLOSED)
            ->whereBetween('closed_at', [$since, $now])
            ->whereNotNull('assigned_at')
            ->get(['assigned_at', 'closed_at']);

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
