<?php

namespace App\Http\Resources;

use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Services\Pm\PmDueCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PmRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $showAdminFields = $isAdmin || $isManager;

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'maintenance_level' => $this->maintenance_level,
            'description' => $this->description,
            'trigger_type' => $this->trigger_type?->value,
            'is_active' => $this->is_active,
            'interval_days' => $this->interval_days,
            'interval_reading' => $this->interval_reading ? (float) $this->interval_reading : null,
            'last_triggered_date' => $this->last_triggered_date?->toDateString(),
            'last_triggered_reading' => $this->last_triggered_reading ? (float) $this->last_triggered_reading : null,
            'next_due_date' => $this->nextDueDate(),
            'next_due_reading' => $this->nextDueReading(),
            'progress_percentage' => $this->progressPercentage(),
            'pm_status' => $this->pmStatus(),
            'created_at' => $this->created_at?->toIso8601String(),
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset?->id,
                'name' => $this->asset?->name,
                'erp_asset_code' => $this->asset?->erp_asset_code,
            ]),
            'usage_reading_type' => $this->whenLoaded('usageReadingType', fn () => [
                'id' => $this->usageReadingType?->id,
                'name' => $this->usageReadingType?->name,
                'unit' => $this->usageReadingType?->unit,
            ]),
            'suppressions' => $this->whenLoaded('suppressions', fn () => $this->suppressions->map(fn ($s) => [
                'id' => $s->id,
                'decision_type' => $s->decision_type,
                'suppressed_until_date' => $s->suppressed_until_date?->toDateString(),
                'suppressed_until_reading' => $s->suppressed_until_reading !== null ? (float) $s->suppressed_until_reading : null,
                'source_mr_id' => $s->maintenance_request_id,
            ])),
        ];

        if ($showAdminFields) {
            $data['created_by'] = $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ]);
        }

        return $data;
    }

    private function nextDueDate(): ?string
    {
        if (! $this->isDateBased()) {
            return null;
        }

        if ($this->interval_days === null || $this->last_triggered_date === null) {
            return null;
        }

        return $this->last_triggered_date->addDays($this->interval_days)->toDateString();
    }

    private function nextDueReading(): ?float
    {
        if (! $this->isReadingBased()) {
            return null;
        }

        if ($this->interval_reading === null || $this->last_triggered_reading === null) {
            return null;
        }

        return (float) $this->last_triggered_reading + (float) $this->interval_reading;
    }

    private function progressPercentage(): ?float
    {
        $dateProgress = $this->dateProgress();
        $readingProgress = $this->readingProgress();

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

        $progress = $this->progressPercentage();

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

    private function dateProgress(): ?float
    {
        if (! $this->isDateBased()) {
            return null;
        }

        if ($this->interval_days === null || $this->last_triggered_date === null) {
            return null;
        }

        $elapsed = abs(now()->diffInDays($this->last_triggered_date));

        return min(100.0, ($elapsed / $this->interval_days) * 100);
    }

    private function readingProgress(): ?float
    {
        if (! $this->isReadingBased()) {
            return null;
        }

        if ($this->interval_reading === null || $this->last_triggered_reading === null) {
            return null;
        }

        $reading = $this->resource->latestConfirmedReading();

        if ($reading === null) {
            return null;
        }

        $elapsed = (float) $reading->reading_value - (float) $this->last_triggered_reading;

        return min(100.0, ($elapsed / (float) $this->interval_reading) * 100);
    }

    private function isDateBased(): bool
    {
        return in_array($this->trigger_type, [PmTriggerType::DATE, PmTriggerType::DATE_OR_READING]);
    }

    private function isReadingBased(): bool
    {
        return in_array($this->trigger_type, [PmTriggerType::READING, PmTriggerType::DATE_OR_READING]);
    }
}
