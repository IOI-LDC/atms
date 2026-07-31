<?php

namespace App\Queries\Reports;

use App\Models\PmOccurrenceSuppression;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * R-21: read-only PM suppression audit register.
 */
class PmSuppressionReportQuery
{
    /**
     * @param  array{pm_rule_id?: ?int, asset_id?: ?int, decision_type?: ?string}  $filters
     * @return array{summary: array{total_suppressions: int}, paginator: CursorPaginator}
     */
    public function handle(int $perPage, Carbon $from, Carbon $to, array $filters): array
    {
        $base = PmOccurrenceSuppression::query()
            ->whereBetween('decided_at', [$from, $to])
            ->when($filters['pm_rule_id'] ?? null, fn ($query, $pmRuleId) =>
                $query->where('pm_rule_id', $pmRuleId))
            ->when($filters['asset_id'] ?? null, fn ($query, $assetId) =>
                $query->where('asset_id', $assetId))
            ->when($filters['decision_type'] ?? null, fn ($query, $decisionType) =>
                $query->where('decision_type', $decisionType));

        $summary = ['total_suppressions' => (clone $base)->count()];

        $paginator = (clone $base)
            ->with(['pmRule', 'asset', 'maintenanceRequest', 'decidedBy', 'triggerReadingType'])
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return ['summary' => $summary, 'paginator' => $paginator];
    }
}
