<?php

namespace App\Services\Pm;

use App\Enums\PmTriggerType;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\PmOccurrenceSuppression;
use Illuminate\Support\Collection;

class PmDueCalculator
{
    public function isDue(AssetPmAssignment $assignment, ?Collection $readings = null, ?Collection $suppressions = null): bool
    {
        if (! $assignment->is_active || ! $assignment->pmRule?->is_active) {
            return false;
        }

        return match ($assignment->pmRule->trigger_type) {
            PmTriggerType::DATE => $this->isDueByDate($assignment) && ! $this->isSuppressedByDate($assignment, $suppressions),
            PmTriggerType::READING => $this->isDueByReading($assignment, $readings) && ! $this->isSuppressedByReading($assignment, $readings, $suppressions),
            PmTriggerType::DATE_OR_READING => $this->isDueByDateOrReading($assignment, $readings, $suppressions),
        };
    }

    private function isDueByDate(AssetPmAssignment $assignment): bool
    {
        if ($assignment->last_triggered_date === null) {
            return true;
        }

        return $assignment->last_triggered_date->addDays($assignment->pmRule->interval_days)->isPast();
    }

    private function isDueByReading(AssetPmAssignment $assignment, ?Collection $readings = null): bool
    {
        $rule = $assignment->pmRule;

        $latestConfirmed = $readings
            ? $readings->get("{$assignment->asset_id}_{$rule->usage_reading_type_id}")
            : AssetMeterReading::where('asset_id', $assignment->asset_id)
                ->where('usage_reading_type_id', $rule->usage_reading_type_id)
                ->whereNotNull('confirmed_at')
                ->orderByDesc('reading_at')
                ->first();

        if (! $latestConfirmed) {
            return false;
        }

        $baseline = (float) ($assignment->last_triggered_reading ?? 0);
        $threshold = $baseline + (float) $rule->interval_reading;

        return (float) $latestConfirmed->reading_value >= $threshold;
    }

    private function isDueByDateOrReading(AssetPmAssignment $assignment, ?Collection $readings = null, ?Collection $suppressions = null): bool
    {
        $dateDue = $this->isDueByDate($assignment);
        $readingDue = $this->isDueByReading($assignment, $readings);

        if ($dateDue && $this->isSuppressedByDate($assignment, $suppressions)) {
            $dateDue = false;
        }

        if ($readingDue && $this->isSuppressedByReading($assignment, $readings, $suppressions)) {
            $readingDue = false;
        }

        return $dateDue || $readingDue;
    }

    private function isSuppressedByDate(AssetPmAssignment $assignment, ?Collection $suppressions = null): bool
    {
        if ($suppressions !== null) {
            return $suppressions->get("{$assignment->id}_date", collect())
                ->contains(fn ($s) => $s['suppressed_until_date'] >= now()->toDateString());
        }

        return PmOccurrenceSuppression::where('pm_rule_id', $assignment->pm_rule_id)
            ->where('asset_id', $assignment->asset_id)
            ->where('triggered_by_date', true)
            ->where('suppressed_until_date', '>=', now()->toDateString())
            ->exists();
    }

    private function isSuppressedByReading(AssetPmAssignment $assignment, ?Collection $readings = null, ?Collection $suppressions = null): bool
    {
        $rule = $assignment->pmRule;

        $latestConfirmedValue = $readings
            ? optional($readings->get("{$assignment->asset_id}_{$rule->usage_reading_type_id}"))->reading_value
            : AssetMeterReading::where('asset_id', $assignment->asset_id)
                ->where('usage_reading_type_id', $rule->usage_reading_type_id)
                ->whereNotNull('confirmed_at')
                ->orderByDesc('reading_at')
                ->value('reading_value');

        if ($latestConfirmedValue === null) {
            return false;
        }

        if ($suppressions !== null) {
            return $suppressions->get("{$assignment->id}_reading", collect())
                ->contains(fn ($s) => (float) $s['suppressed_until_reading'] >= (float) $latestConfirmedValue);
        }

        return PmOccurrenceSuppression::where('pm_rule_id', $assignment->pm_rule_id)
            ->where('asset_id', $assignment->asset_id)
            ->where('triggered_by_reading', true)
            ->whereRaw('suppressed_until_reading >= ?', [(float) $latestConfirmedValue])
            ->exists();
    }

    public function isTriggeredByDate(AssetPmAssignment $assignment): bool
    {
        return match ($assignment->pmRule->trigger_type) {
            PmTriggerType::DATE => true,
            PmTriggerType::READING => false,
            PmTriggerType::DATE_OR_READING => $this->isDueByDate($assignment) && ! $this->isSuppressedByDate($assignment, null),
        };
    }

    public function isTriggeredByReading(AssetPmAssignment $assignment): bool
    {
        return match ($assignment->pmRule->trigger_type) {
            PmTriggerType::DATE => false,
            PmTriggerType::READING => true,
            PmTriggerType::DATE_OR_READING => $this->isDueByReading($assignment, null) && ! $this->isSuppressedByReading($assignment, null, null),
        };
    }
}
