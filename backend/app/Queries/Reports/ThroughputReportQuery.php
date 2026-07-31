<?php

namespace App\Queries\Reports;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * R-16: MR/WO lifecycle throughput, grouped by the timestamp of each event.
 */
class ThroughputReportQuery
{
    /**
     * @param  array{status?: ?string}  $filters
     * @return array{summary: array<string, int|float|null>, paginator: CursorPaginator}
     */
    public function handle(int $perPage, Carbon $from, Carbon $to, array $filters): array
    {
        $status = $filters['status'] ?? null;
        $mrStatusValues = array_column(MaintenanceRequestStatus::cases(), 'value');
        $woStatusValues = array_column(WorkOrderStatus::cases(), 'value');

        $mrBase = $this->applyStatusFilter(MaintenanceRequest::query(), $status, $mrStatusValues);
        $woBase = $this->applyStatusFilter(WorkOrder::query(), $status, $woStatusValues);

        $convertedRequests = (clone $mrBase)
            ->where('status', MaintenanceRequestStatus::CONVERTED)
            ->whereBetween('reviewed_at', [$from, $to])
            ->get(['id', 'created_at']);
        $firstWorkOrders = WorkOrder::whereIn('maintenance_request_id', $convertedRequests->pluck('id'))
            ->orderBy('created_at')
            ->get(['maintenance_request_id', 'created_at'])
            ->groupBy('maintenance_request_id')
            ->map->first();
        $conversionHours = $convertedRequests->map(function ($request) use ($firstWorkOrders) {
            $workOrder = $firstWorkOrders->get($request->id);

            return $workOrder
                ? abs($request->created_at->diffInHours($workOrder->created_at))
                : null;
        })->filter(fn ($hours) => $hours !== null);

        $summary = [
            'mr_created' => (clone $mrBase)->whereBetween('created_at', [$from, $to])->count(),
            'mr_pending_review' => (clone $mrBase)->where('status', MaintenanceRequestStatus::PENDING_REVIEW)->whereBetween('created_at', [$from, $to])->count(),
            'mr_converted' => $convertedRequests->count(),
            'mr_rejected' => (clone $mrBase)->where('status', MaintenanceRequestStatus::REJECTED)->whereBetween('reviewed_at', [$from, $to])->count(),
            'mr_cancelled' => (clone $mrBase)->where('status', MaintenanceRequestStatus::CANCELLED)->whereBetween('cancelled_at', [$from, $to])->count(),
            'wo_created' => (clone $woBase)->whereBetween('created_at', [$from, $to])->count(),
            'wo_open' => (clone $woBase)->where('status', WorkOrderStatus::OPEN)->whereBetween('created_at', [$from, $to])->count(),
            'wo_in_progress' => (clone $woBase)->whereBetween('started_at', [$from, $to])->count(),
            'wo_completed' => (clone $woBase)->whereBetween('completed_at', [$from, $to])->count(),
            'wo_closed' => (clone $woBase)->whereBetween('closed_at', [$from, $to])->count(),
            'wo_cancelled' => (clone $woBase)->whereBetween('cancelled_at', [$from, $to])->count(),
            'avg_conversion_hours' => $conversionHours->isEmpty() ? null : round($conversionHours->avg(), 2),
        ];

        $events = [
            $this->eventQuery('maintenance_requests', 'created_at', 'mr_created', $from, $to, $status, $mrStatusValues),
            $this->eventQuery('maintenance_requests', 'created_at', 'mr_pending_review', $from, $to, $status, $mrStatusValues, MaintenanceRequestStatus::PENDING_REVIEW->value),
            $this->eventQuery('maintenance_requests', 'reviewed_at', 'mr_converted', $from, $to, $status, $mrStatusValues, MaintenanceRequestStatus::CONVERTED->value),
            $this->eventQuery('maintenance_requests', 'reviewed_at', 'mr_rejected', $from, $to, $status, $mrStatusValues, MaintenanceRequestStatus::REJECTED->value),
            $this->eventQuery('maintenance_requests', 'cancelled_at', 'mr_cancelled', $from, $to, $status, $mrStatusValues, MaintenanceRequestStatus::CANCELLED->value),
            $this->eventQuery('work_orders', 'created_at', 'wo_created', $from, $to, $status, $woStatusValues),
            $this->eventQuery('work_orders', 'created_at', 'wo_open', $from, $to, $status, $woStatusValues, WorkOrderStatus::OPEN->value),
            $this->eventQuery('work_orders', 'started_at', 'wo_in_progress', $from, $to, $status, $woStatusValues),
            $this->eventQuery('work_orders', 'completed_at', 'wo_completed', $from, $to, $status, $woStatusValues),
            $this->eventQuery('work_orders', 'closed_at', 'wo_closed', $from, $to, $status, $woStatusValues),
            $this->eventQuery('work_orders', 'cancelled_at', 'wo_cancelled', $from, $to, $status, $woStatusValues),
        ];

        $unionQuery = array_shift($events);
        foreach ($events as $event) {
            $unionQuery->unionAll($event);
        }

        $dailyQuery = DB::query()->fromSub($unionQuery, 'events')->selectRaw('date');
        foreach (array_keys($summary) as $metric) {
            if ($metric !== 'avg_conversion_hours') {
                $dailyQuery->selectRaw(
                    'COALESCE(SUM(CASE WHEN metric = ? THEN 1 ELSE 0 END), 0) as '.$metric,
                    [$metric]
                );
            }
        }

        $paginator = $dailyQuery
            ->groupBy('date')
            ->orderByDesc('date')
            ->cursorPaginate($perPage);

        return ['summary' => $summary, 'paginator' => $paginator];
    }

    /**
     * @param  array<int, string>  $validStatuses
     */
    private function applyStatusFilter(EloquentBuilder $query, ?string $status, array $validStatuses): EloquentBuilder
    {
        return $query
            ->when($status !== null && in_array($status, $validStatuses, true), fn ($builder) => $builder->where('status', $status))
            ->when($status !== null && ! in_array($status, $validStatuses, true), fn ($builder) => $builder->whereRaw('1 = 0'));
    }

    /**
     * @param  array<int, string>  $validStatuses
     */
    private function eventQuery(
        string $table,
        string $timestampColumn,
        string $metric,
        Carbon $from,
        Carbon $to,
        ?string $status,
        array $validStatuses,
        ?string $requiredStatus = null,
    ): Builder {
        return DB::table($table)
            ->selectRaw("DATE({$timestampColumn}) as date")
            ->selectRaw('? as metric', [$metric])
            ->whereBetween($timestampColumn, [$from, $to])
            ->when($requiredStatus !== null, fn ($query) => $query->where('status', $requiredStatus))
            ->when($status !== null && in_array($status, $validStatuses, true), fn ($query) => $query->where('status', $status))
            ->when($status !== null && ! in_array($status, $validStatuses, true), fn ($query) => $query->whereRaw('1 = 0'));
    }
}
