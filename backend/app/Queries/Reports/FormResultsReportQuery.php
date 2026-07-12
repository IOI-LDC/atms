<?php

namespace App\Queries\Reports;

use App\Enums\FormFieldType;
use App\Models\WorkOrderFormField;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * R-19: Form Results Report
 *
 * Paginated list of work-order form field responses within a date window.
 * Each row includes asset context (id, name, erp_code) and FA subclass code.
 * Summary includes boolean true/false counts and numeric pre/post averages.
 */
class FormResultsReportQuery
{
    /**
     * @param  array{asset_id?: ?int, fa_subclass_code?: ?string, field_uuid?: ?string}  $filters
     * @return array{summary: array<string, int|float|null>, paginator: CursorPaginator}
     */
    public function handle(int $perPage, Carbon $from, Carbon $to, array $filters): array
    {
        $base = WorkOrderFormField::whereHas('workOrderForm.workOrder', fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->when($filters['asset_id'] ?? null, fn ($q, $v) =>
                $q->whereHas('workOrderForm.workOrder', fn ($sub) => $sub->where('asset_id', $v)))
            ->when($filters['fa_subclass_code'] ?? null, fn ($q, $v) =>
                $q->whereHas('workOrderForm.workOrder.asset', fn ($sub) => $sub->where('fa_subclass_code', $v)))
            ->when($filters['field_uuid'] ?? null, fn ($q, $v) => $q->where('uuid', $v));

        $summaryRow = (clone $base)
            ->selectRaw('COUNT(*) as total_fields')
            ->selectRaw('COALESCE(SUM(CASE WHEN field_type = ? AND post_value IN (?, ?) THEN 1 ELSE 0 END), 0) as boolean_true_count', [
                FormFieldType::BOOLEAN->value,
                'true',
                '1',
            ])
            ->selectRaw('COALESCE(SUM(CASE WHEN field_type = ? AND post_value IN (?, ?) THEN 1 ELSE 0 END), 0) as boolean_false_count', [
                FormFieldType::BOOLEAN->value,
                'false',
                '0',
            ])
            ->first();
        $summary = [
            'total_fields' => (int) ($summaryRow->total_fields ?? 0),
            'boolean_true_count' => (int) ($summaryRow->boolean_true_count ?? 0),
            'boolean_false_count' => (int) ($summaryRow->boolean_false_count ?? 0),
        ];

        $numericPattern = '^[+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)$';
        $numericComparisons = (clone $base)
            ->where('field_type', FormFieldType::NUMERIC)
            ->where('has_pre_post', true)
            ->whereRaw('pre_value ~ ?', [$numericPattern])
            ->whereRaw('post_value ~ ?', [$numericPattern])
            ->select(['uuid', 'label', 'unit'])
            ->selectRaw('COUNT(*) as comparison_count')
            ->selectRaw('AVG(CAST(pre_value AS NUMERIC)) as avg_pre_value')
            ->selectRaw('AVG(CAST(post_value AS NUMERIC)) as avg_post_value')
            ->groupBy('uuid', 'label', 'unit')
            ->orderBy('label')
            ->orderBy('unit')
            ->orderBy('uuid')
            ->get()
            ->map(function ($row) {
                $preAverage = (float) $row->avg_pre_value;
                $postAverage = (float) $row->avg_post_value;

                return [
                    'field_uuid' => $row->uuid,
                    'label' => $row->label,
                    'unit' => $row->unit,
                    'comparison_count' => (int) $row->comparison_count,
                    'avg_pre_value' => round($preAverage, 2),
                    'avg_post_value' => round($postAverage, 2),
                    'avg_change' => round($postAverage - $preAverage, 2),
                ];
            })
            ->all();

        $summary['numeric_pre_post_count'] = collect($numericComparisons)->sum('comparison_count');
        $summary['numeric_comparisons'] = $numericComparisons;

        // Paginated rows with eager-loaded relations for context
        $paginator = (clone $base)
            ->with([
                'workOrderForm.workOrder.asset' => fn ($q) => $q->select('id', 'name', 'erp_asset_code', 'fa_subclass_code'),
            ])
            ->orderBy('uuid')
            ->orderBy('id')
            ->cursorPaginate($perPage);

        return [
            'summary' => $summary,
            'paginator' => $paginator,
        ];
    }
}
