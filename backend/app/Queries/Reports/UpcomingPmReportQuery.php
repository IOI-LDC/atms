<?php

namespace App\Queries\Reports;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PmTriggerType;
use App\Enums\WorkOrderStatus;
use App\Models\AssetPmAssignment;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * R-1: upcoming date-triggered PM schedule. Loads eligible assignments
 * (mirrors EvaluatePmRulesJob: active assignment + active rule + enrolled
 * asset, date-triggered only), then projects next_due from the calculator's
 * null-policy (never-triggered = due-now, excluded from the forward window).
 * Chain status is resolved in bulk (3 queries: pending MRs + active WOs +
 * eager-loaded MR relation) to avoid the N+1 of
 * AssetPmAssignment::hasActiveChain(). Output is sorted by next_due_date
 * (soonest first) for deterministic ordering.
 */
class UpcomingPmReportQuery
{
    /**
     * @param  array{location_id?: ?int, pm_rule_id?: ?int}  $filters
     * @return array{summary: array{total: int, by_trigger_type: array<string, int>, by_due_week: array<string, int>}, items: Collection<int, array{assignment: AssetPmAssignment, next_due_date: Carbon, days_until_due: int, chain_status: string}>}
     */
    public function handle(int $days, array $filters): array
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays($days);

        $assignments = AssetPmAssignment::where('is_active', true)
            ->whereHas('pmRule', fn ($q) => $q->where('is_active', true)
                ->whereIn('trigger_type', [PmTriggerType::DATE, PmTriggerType::DATE_OR_READING]))
            ->whereHas('asset', fn ($q) => $q->where('maintenance_status', MaintenanceStatus::ENROLLED))
            ->when($filters['pm_rule_id'] ?? null, fn ($q, $v) => $q->where('pm_rule_id', $v))
            ->when($filters['location_id'] ?? null, fn ($q, $v) =>
                $q->whereHas('asset', fn ($aq) => $aq->where('current_location_id', $v)))
            ->with(['asset.currentLocation', 'pmRule'])
            ->get();

        $chainStatus = $this->resolveChainStatuses($assignments);

        $rows = $assignments->map(function (AssetPmAssignment $a) use ($today, $horizon, $chainStatus) {
            // Never-triggered assignments are due-now per the calculator policy,
            // so they are not "upcoming" and are excluded from the forward window.
            if ($a->last_triggered_date === null) {
                return null;
            }

            $nextDue = $a->last_triggered_date->copy()->addDays($a->pmRule->interval_days);

            if ($nextDue < $today || $nextDue > $horizon) {
                return null;
            }

            return [
                'assignment' => $a,
                'next_due_date' => $nextDue,
                'days_until_due' => abs((int) $today->diffInDays($nextDue)),
                'chain_status' => $chainStatus["{$a->asset_id}_{$a->pm_rule_id}"] ?? 'not_yet_generated',
            ];
        })->filter()->sortBy('next_due_date')->values();

        return [
            'summary' => [
                'total' => $rows->count(),
                'by_trigger_type' => $rows->countBy(fn ($r) => $r['assignment']->pmRule->trigger_type->value)->toArray(),
                'by_due_week' => $rows->countBy(fn ($r) => $r['next_due_date']->format('o-\WW'))->sortKeys()->toArray(),
            ],
            'items' => $rows,
        ];
    }

    /**
     * Bulk-resolve chain status for an assignment set in 3 queries (pending PM
     * MRs + active WOs + eager-loaded MR relation), keyed by
     * "asset_id|pm_rule_id". Replaces the per-row
     * AssetPmAssignment::hasActiveChain() (2 queries each = N+1).
     *
     * @return array<string, string>
     */
    private function resolveChainStatuses(Collection $assignments): array
    {
        if ($assignments->isEmpty()) {
            return [];
        }

        $assetIds = $assignments->pluck('asset_id')->all();
        $ruleIds = $assignments->pluck('pm_rule_id')->unique()->all();

        $pending = MaintenanceRequest::where('is_preventive', true)
            ->where('status', MaintenanceRequestStatus::PENDING_REVIEW)
            ->whereIn('asset_id', $assetIds)
            ->whereIn('pm_rule_id', $ruleIds)
            ->get(['asset_id', 'pm_rule_id'])
            ->keyBy(fn ($m) => "{$m->asset_id}_{$m->pm_rule_id}");

        $activeWos = WorkOrder::whereIn('asset_id', $assetIds)
            ->whereIn('status', [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::COMPLETED])
            ->whereHas('maintenanceRequest', fn ($q) => $q->where('is_preventive', true)->whereIn('pm_rule_id', $ruleIds))
            ->with('maintenanceRequest:id,pm_rule_id')
            ->get()
            ->keyBy(fn ($w) => "{$w->asset_id}_{$w->maintenanceRequest->pm_rule_id}");

        $map = [];
        foreach ($pending->keys() as $key) {
            $map[$key] = 'generated_mr_pending';
        }
        foreach ($activeWos as $key => $wo) {
            if (isset($map[$key])) {
                continue; // Pending MR takes precedence over an active WO.
            }
            $map[$key] = $wo->status === WorkOrderStatus::COMPLETED ? 'wo_completed' : 'wo_open';
        }

        return $map;
    }
}
