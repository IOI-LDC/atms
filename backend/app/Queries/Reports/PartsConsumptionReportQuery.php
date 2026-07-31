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
 * Rows are grouped by part, Asset Class, and canonical Asset Size. Part
 * identity fields (name, supplier Part Number, Part Size, Maintenance
 * Category, unit of measure, availability snapshot) are properties of the
 * part and travel with each row without creating additional aggregation
 * ambiguity. Asset FA subclass and size are current-state context because
 * work_order_parts has no completion-time snapshot. Inventory
 * issue/warehouse state remains owned by SM/ERP.
 *
 * Cursor ordering covers every grouping dimension through non-null sort
 * keys (missing class/size coalesce to stable bucket strings), so pagination
 * is deterministic with no duplicates or gaps between pages.
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

        $assetClassExpr = "coalesce(nullif(assets.fa_subclass_code, ''), 'Unclassified')";
        $assetSizeExpr = "coalesce(assets.size_inches::text, 'unspecified')";

        $grouped = (clone $base)
            ->selectRaw('work_order_parts.part_id as part_id')
            ->selectRaw('parts.name as part_name')
            ->selectRaw('parts.part_number as part_number')
            ->selectRaw('parts.unit_of_measure as unit_of_measure')
            ->selectRaw('parts.size_inches as part_size_inches')
            ->selectRaw('parts.available_quantity as available_quantity')
            ->selectRaw('parts.maintenance_category_id as part_maintenance_category_id')
            ->selectRaw('maintenance_categories.code as part_category_code')
            ->selectRaw('maintenance_categories.name as part_category_name')
            ->selectRaw("{$assetClassExpr} as asset_class")
            ->selectRaw("{$assetSizeExpr} as asset_size_key")
            ->selectRaw('sum(work_order_parts.quantity) as total_quantity')
            ->selectRaw('count(*) as line_item_count')
            ->selectRaw('count(distinct work_order_parts.work_order_id) as work_order_count')
            ->groupBy([
                'work_order_parts.part_id',
                'parts.name',
                'parts.part_number',
                'parts.unit_of_measure',
                'parts.size_inches',
                'parts.available_quantity',
                'parts.maintenance_category_id',
                'maintenance_categories.code',
                'maintenance_categories.name',
                DB::raw($assetClassExpr),
                DB::raw($assetSizeExpr),
            ]);

        $paginator = DB::query()
            ->fromSub($grouped, 'consumption')
            ->orderBy('part_id')
            ->orderBy('asset_class')
            ->orderBy('asset_size_key')
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
            ->leftJoin('maintenance_categories', 'maintenance_categories.id', '=', 'parts.maintenance_category_id')
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
