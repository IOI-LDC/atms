<?php

namespace App\Queries\Dashboard\Kpis;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Carbon\Carbon;

/**
 * Workforce & backlog KPIs for the executive dashboard.
 *
 * - wo_backlog.total: org-wide count of open/in-progress work orders right now
 *   (same status basis as R-14, but without role scoping — this is an
 *   org-wide executive figure, consistent with the reports model).
 * - wo_backlog.trend_pct: current backlog versus the backlog reconstructed at
 *   the start of the window. A work order was "in the backlog" at $since when
 *   it was created on/before $since and had not yet left the backlog (completed
 *   or cancelled) at that time. Negative = shrinking backlog (improving).
 *   Null when the prior backlog was zero (no basis for a percentage).
 * - completion_rate: work orders closed within the window divided by work
 *   orders created within the window. Null when nothing was created.
 */
class WorkforceKpiQuery
{
    /**
     * @return array{
     *     workforce: array{
     *         wo_backlog: array{total: int, trend_pct: float|null},
     *         completion_rate: array{closed: int, created: int, percentage: float|null},
     *     },
     * }
     */
    public function handle(Carbon $since, Carbon $now): array
    {
        $current = $this->backlogCount();
        $prior = $this->backlogAsOf($since);

        $closed = WorkOrder::whereBetween('closed_at', [$since, $now])->count();
        $created = WorkOrder::whereBetween('created_at', [$since, $now])->count();

        return [
            'workforce' => [
                'wo_backlog' => [
                    'total' => $current,
                    'trend_pct' => $prior > 0 ? round(($current - $prior) / $prior * 100, 1) : null,
                ],
                'completion_rate' => [
                    'closed' => $closed,
                    'created' => $created,
                    'percentage' => $created > 0 ? round($closed / $created * 100, 1) : null,
                ],
            ],
        ];
    }

    private function backlogCount(): int
    {
        return WorkOrder::whereIn('status', [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS])->count();
    }

    /**
     * Reconstruct the backlog as it stood at $since. A work order leaves the
     * backlog when it is completed or cancelled, so anything created on/before
     * $since that had not yet completed or cancelled at that time was still in
     * the backlog then.
     */
    private function backlogAsOf(Carbon $since): int
    {
        return WorkOrder::where('created_at', '<=', $since)
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>', $since))
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $since))
            ->count();
    }
}
