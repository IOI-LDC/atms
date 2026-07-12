<?php

namespace App\Queries\Reports;

use App\Models\AssetLocationHistory;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * R-18: Asset Movement Report
 *
 * Tracks asset movements between locations over a date window:
 * - Total number of movements
 * - Number of unique assets moved
 * - Detailed movement records (from/to locations, reason, timestamp)
 *
 * Uses the asset_location_histories table to track all location changes.
 * Cursor-paginated with deterministic ordering (effective_at, id).
 */
class AssetMovementReportQuery
{
    /**
     * @param  array{asset_id?: ?int, from_location_id?: ?int, to_location_id?: ?int}  $filters
     * @return array{summary: array{total_movements: int, unique_assets_moved: int}, paginator: CursorPaginator}
     */
    public function handle(int $perPage, Carbon $from, Carbon $to, array $filters): array
    {
        // Summary query (non-paginated)
        $summaryQuery = AssetLocationHistory::whereBetween('effective_at', [$from, $to])
            ->when($filters['asset_id'] ?? null, fn ($q, $v) => $q->where('asset_id', $v))
            ->when($filters['from_location_id'] ?? null, fn ($q, $v) => $q->where('from_location_id', $v))
            ->when($filters['to_location_id'] ?? null, fn ($q, $v) => $q->where('to_location_id', $v));

        $totalMovements = (clone $summaryQuery)->count();
        $uniqueAssetsMoved = (clone $summaryQuery)->distinct('asset_id')->count('asset_id');

        // Paginated query with deterministic ordering
        $paginator = AssetLocationHistory::whereBetween('effective_at', [$from, $to])
            ->when($filters['asset_id'] ?? null, fn ($q, $v) => $q->where('asset_id', $v))
            ->when($filters['from_location_id'] ?? null, fn ($q, $v) => $q->where('from_location_id', $v))
            ->when($filters['to_location_id'] ?? null, fn ($q, $v) => $q->where('to_location_id', $v))
            ->with(['asset', 'fromLocation', 'toLocation', 'changedBy'])
            ->orderBy('effective_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate($perPage);

        return [
            'summary' => [
                'total_movements' => $totalMovements,
                'unique_assets_moved' => $uniqueAssetsMoved,
            ],
            'paginator' => $paginator,
        ];
    }
}
