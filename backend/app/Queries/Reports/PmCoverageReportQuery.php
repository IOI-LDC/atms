<?php

namespace App\Queries\Reports;

use App\Models\Asset;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * R-9: PM Coverage / Gaps.
 *
 * Identifies active assets that have NO active PM assignment. This directly
 * answers "are we maintaining everything we should be?" — a common audit
 * finding in O&G maintenance programs. Returns paginated list of uncovered
 * assets with summary counts.
 */
class PmCoverageReportQuery
{
    /**
     * @param  array{location_id?: ?int, asset_kind?: ?string}  $filters
     * @return array{summary: array{total_assets: int, covered_assets: int, uncovered_assets: int, coverage_pct: float|null}, paginator: CursorPaginator}
     */
    public function handle(int $perPage, array $filters): array
    {
        // Count total active assets (with filters)
        $totalAssets = Asset::where('is_active', true)
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('current_location_id', $v))
            ->when($filters['asset_kind'] ?? null, fn ($q, $v) => $q->where('asset_kind', $v))
            ->count();

        // Count covered assets (have at least one active PM assignment)
        $coveredAssets = Asset::where('is_active', true)
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('current_location_id', $v))
            ->when($filters['asset_kind'] ?? null, fn ($q, $v) => $q->where('asset_kind', $v))
            ->whereHas('pmAssignments', fn ($q) => $q->where('is_active', true))
            ->count();

        $uncoveredAssets = $totalAssets - $coveredAssets;
        $coveragePct = $totalAssets > 0 ? round($coveredAssets / $totalAssets * 100, 1) : null;

        // Paginated list of uncovered assets
        $paginator = Asset::where('is_active', true)
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('current_location_id', $v))
            ->when($filters['asset_kind'] ?? null, fn ($q, $v) => $q->where('asset_kind', $v))
            ->whereDoesntHave('pmAssignments', fn ($q) => $q->where('is_active', true))
            ->with('currentLocation')
            ->orderBy('name')
            ->orderBy('id')
            ->cursorPaginate($perPage);

        return [
            'summary' => [
                'total_assets' => $totalAssets,
                'covered_assets' => $coveredAssets,
                'uncovered_assets' => $uncoveredAssets,
                'coverage_pct' => $coveragePct,
            ],
            'paginator' => $paginator,
        ];
    }
}
