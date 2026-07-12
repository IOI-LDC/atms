<?php

namespace App\Queries\Reports;

use App\Enums\WorkOrderStatus;
use App\Models\Part;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * R-17: finalized work-order parts usage for manual ERP handoff.
 *
 * Quantities are grouped only within the same part/UOM. Asset FA subclass is
 * current-state context because work_order_parts has no completion-time class
 * snapshot. Inventory issue/warehouse state remains owned by SM/ERP.
 */
class PartsConsumptionReportQuery
{
    /**
     * @param  array{part_id?: ?int, asset_id?: ?int, fa_subclass_code?: ?string}  $filters
     * @return array{summary: array{total_line_items: int, distinct_parts: int, distinct_work_orders: int, total_quantity: ?float, unit_of_measure: ?string}, paginator: CursorPaginator}
     */
    public function handle(int $perPage, Carbon $from, Carbon $to, array $filters): array
    {
        $base = $this->baseQuery($from, $to, $filters);
        $summaryRow = (clone $base)
            ->selectRaw('count(*) as total_line_items')
            ->selectRaw('count(distinct work_order_parts.part_id) as distinct_parts')
            ->selectRaw('count(distinct work_order_parts.work_order_id) as distinct_work_orders')
            ->selectRaw('sum(work_order_parts.quantity) as filtered_quantity')
            ->first();

        $partId = $filters['part_id'] ?? null;
        $summary = [
            'total_line_items' => (int) ($summaryRow->total_line_items ?? 0),
            'distinct_parts' => (int) ($summaryRow->distinct_parts ?? 0),
            'distinct_work_orders' => (int) ($summaryRow->distinct_work_orders ?? 0),
            'total_quantity' => $partId !== null ? (float) ($summaryRow->filtered_quantity ?? 0) : null,
            'unit_of_measure' => $partId !== null ? Part::whereKey($partId)->value('unit_of_measure') : null,
        ];

        $grouped = (clone $base)
            ->selectRaw('work_order_parts.part_id as part_id')
            ->selectRaw('parts.erp_part_code as part_code')
            ->selectRaw('parts.name as part_name')
            ->selectRaw('parts.unit_of_measure as unit_of_measure')
            ->selectRaw("coalesce(nullif(assets.fa_subclass_code, ''), 'Unclassified') as fa_subclass_code")
            ->selectRaw('sum(work_order_parts.quantity) as total_quantity')
            ->selectRaw('count(*) as line_item_count')
            ->selectRaw('count(distinct work_order_parts.work_order_id) as work_order_count')
            ->groupBy([
                'work_order_parts.part_id',
                'parts.erp_part_code',
                'parts.name',
                'parts.unit_of_measure',
                'assets.fa_subclass_code',
            ]);

        $paginator = DB::query()
            ->fromSub($grouped, 'consumption')
            ->orderBy('part_id')
            ->orderBy('fa_subclass_code')
            ->cursorPaginate($perPage);

        return ['summary' => $summary, 'paginator' => $paginator];
    }

    /**
     * @param  array{part_id?: ?int, asset_id?: ?int, fa_subclass_code?: ?string}  $filters
     */
    private function baseQuery(Carbon $from, Carbon $to, array $filters): Builder
    {
        return DB::table('work_order_parts')
            ->join('work_orders', 'work_orders.id', '=', 'work_order_parts.work_order_id')
            ->join('parts', 'parts.id', '=', 'work_order_parts.part_id')
            ->join('assets', 'assets.id', '=', 'work_orders.asset_id')
            ->whereIn('work_orders.status', [WorkOrderStatus::COMPLETED->value, WorkOrderStatus::CLOSED->value])
            ->whereNotNull('work_orders.completed_at')
            ->whereBetween('work_orders.completed_at', [$from, $to])
            ->when($filters['part_id'] ?? null, fn (Builder $query, int $partId) =>
                $query->where('work_order_parts.part_id', $partId))
            ->when($filters['asset_id'] ?? null, fn (Builder $query, int $assetId) =>
                $query->where('work_orders.asset_id', $assetId))
            ->when($filters['fa_subclass_code'] ?? null, fn (Builder $query, string $faSubclassCode) =>
                $query->where('assets.fa_subclass_code', $faSubclassCode));
    }
}
