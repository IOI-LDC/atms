<?php

namespace App\Http\Resources;

use App\Enums\PmTriggerType;
use App\Services\Pm\PmDueCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetPmAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rule = $this->pmRule;

        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'pm_rule_id' => $this->pm_rule_id,
            'is_active' => $this->is_active,
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset?->id,
                'name' => $this->asset?->name,
                'erp_asset_code' => $this->asset?->erp_asset_code,
            ]),
            'last_triggered_date' => $this->last_triggered_date?->toDateString(),
            'last_triggered_reading' => $this->last_triggered_reading ? (float) $this->last_triggered_reading : null,
            'next_due_date' => $this->nextDueDate($rule),
            'next_due_reading' => $this->nextDueReading($rule),
            'progress_percentage' => $this->progressPercentage($rule),
            'pm_status' => $this->pmStatus(),
            'rule' => [
                'id' => $rule?->id,
                'name' => $rule?->name,
                'maintenance_level' => $rule?->maintenance_level,
                'trigger_type' => $rule?->trigger_type?->value,
                'interval_days' => $rule?->interval_days,
                'interval_reading' => $rule?->interval_reading ? (float) $rule->interval_reading : null,
                'usage_reading_type' => $rule && $rule->relationLoaded('usageReadingType') ? [
                    'id' => $rule->usageReadingType?->id,
                    'name' => $rule->usageReadingType?->name,
                    'unit' => $rule->usageReadingType?->unit,
                ] : null,
            ],
            'assigned_by' => $this->whenLoaded('assignedBy', fn () => [
                'id' => $this->assignedBy?->id,
                'name' => $this->assignedBy?->name,
            ]),
            'assigned_at' => $this->created_at?->toIso8601String(),
            'suppressions' => $this->whenLoaded('suppressions', fn () => $this->suppressions->map(fn ($s) => [
                'id' => $s->id,
                'decision_type' => $s->decision_type,
                'suppressed_until_date' => $s->suppressed_until_date?->toDateString(),
                'suppressed_until_reading' => $s->suppressed_until_reading !== null ? (float) $s->suppressed_until_reading : null,
                'source_mr_id' => $s->maintenance_request_id,
            ])),
        ];
    }

    private function nextDueDate($rule): ?string
    {
        if (! $this->isDateBased($rule)) {
            return null;
        }

        if ($rule?->interval_days === null || $this->last_triggered_date === null) {
            return null;
        }

        return $this->last_triggered_date->addDays($rule->interval_days)->toDateString();
    }

    private function nextDueReading($rule): ?float
    {
        if (! $this->isReadingBased($rule)) {
            return null;
        }

        if ($rule?->interval_reading === null || $this->last_triggered_reading === null) {
            return null;
        }

        return (float) $this->last_triggered_reading + (float) $rule->interval_reading;
    }

    private function progressPercentage($rule): ?float
    {
        $dateProgress = $this->dateProgress($rule);
        $readingProgress = $this->readingProgress($rule);

        $applicable = array_filter([$dateProgress, $readingProgress], fn ($v) => $v !== null);

        if (empty($applicable)) {
            return null;
        }

        return round(max($applicable), 1);
    }

    private function pmStatus(): string
    {
        if (app(PmDueCalculator::class)->isDue($this->resource)) {
            return 'due';
        }

        $progress = $this->progressPercentage($this->pmRule);

        if ($progress === null) {
            return 'ok';
        }

        if ($progress >= 80.0) {
            return 'due';
        }

        if ($progress >= 60.0) {
            return 'soon';
        }

        return 'ok';
    }

    private function dateProgress($rule): ?float
    {
        if (! $this->isDateBased($rule)) {
            return null;
        }

        if ($rule?->interval_days === null || $this->last_triggered_date === null) {
            return null;
        }

        $elapsed = abs(now()->diffInDays($this->last_triggered_date));

        return min(100.0, ($elapsed / $rule->interval_days) * 100);
    }

    private function readingProgress($rule): ?float
    {
        if (! $this->isReadingBased($rule)) {
            return null;
        }

        if ($rule?->interval_reading === null || $this->last_triggered_reading === null) {
            return null;
        }

        $reading = $this->resource->latestConfirmedReading();

        if ($reading === null) {
            return null;
        }

        $elapsed = (float) $reading->reading_value - (float) $this->last_triggered_reading;

        return min(100.0, ($elapsed / (float) $rule->interval_reading) * 100);
    }

    private function isDateBased($rule): bool
    {
        return in_array($rule?->trigger_type, [PmTriggerType::DATE, PmTriggerType::DATE_OR_READING]);
    }

    private function isReadingBased($rule): bool
    {
        return in_array($rule?->trigger_type, [PmTriggerType::READING, PmTriggerType::DATE_OR_READING]);
    }
}
