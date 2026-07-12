<?php

namespace App\Queries\Reports;

use App\Enums\FormFieldType;
use App\Models\WorkOrderFormField;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * R-19: Form Results Report
 *
 * Aggregates work order form field responses within a date window:
 * - Boolean fields: count of true/false responses
 * - Numeric fields with pre/post: comparison of pre vs post values
 * - Text fields: count of responses
 *
 * Groups by field label and aggregates responses.
 */
class FormResultsReportQuery
{
    public function handle(Carbon $from, Carbon $to): array
    {
        $fields = WorkOrderFormField::whereHas('workOrderForm.workOrder', fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->get();

        $totalFields = $fields->count();

        // Group by label
        $grouped = $fields->groupBy('label');

        $items = $grouped->map(function (Collection $fieldResponses, string $label) {
            $first = $fieldResponses->first();
            $fieldType = $first->field_type;
            $hasPrePost = $first->has_pre_post;
            $unit = $first->unit;

            $result = [
                'label' => $label,
                'field_type' => $fieldType->value,
                'has_pre_post' => $hasPrePost,
                'unit' => $unit,
                'response_count' => $fieldResponses->count(),
            ];

            // Boolean fields: count true/false
            if ($fieldType === FormFieldType::BOOLEAN) {
                $trueCount = $fieldResponses->filter(fn ($f) => $f->post_value === 'true' || $f->post_value === '1')->count();
                $falseCount = $fieldResponses->count() - $trueCount;
                $result['true_count'] = $trueCount;
                $result['false_count'] = $falseCount;
            }

            // Numeric fields with pre/post: calculate averages
            if ($fieldType === FormFieldType::NUMERIC && $hasPrePost) {
                $preValues = $fieldResponses
                    ->filter(fn ($f) => $f->pre_value !== null && is_numeric($f->pre_value))
                    ->pluck('pre_value')
                    ->map(fn ($v) => (float) $v);
                $postValues = $fieldResponses
                    ->filter(fn ($f) => $f->post_value !== null && is_numeric($f->post_value))
                    ->pluck('post_value')
                    ->map(fn ($v) => (float) $v);

                $result['avg_pre_value'] = $preValues->isEmpty() ? null : round($preValues->avg(), 2);
                $result['avg_post_value'] = $postValues->isEmpty() ? null : round($postValues->avg(), 2);
                $result['avg_change'] = ($result['avg_pre_value'] !== null && $result['avg_post_value'] !== null)
                    ? round($result['avg_post_value'] - $result['avg_pre_value'], 2)
                    : null;
            }

            return $result;
        })->sortBy('label')->values()->all();

        return [
            'summary' => [
                'total_fields' => $totalFields,
            ],
            'items' => $items,
        ];
    }
}
