<?php

namespace App\Services\Pm;

use App\Enums\PmTriggerType;
use App\Models\AssetMeterReading;
use App\Models\PmOccurrenceSuppression;
use App\Models\PmRule;

class PmDueCalculator
{
    public function isDue(PmRule $rule): bool
    {
        if (! $rule->is_active) {
            return false;
        }

        return match ($rule->trigger_type) {
            PmTriggerType::DATE => $this->isDueByDate($rule) && ! $this->isSuppressedByDate($rule),
            PmTriggerType::READING => $this->isDueByReading($rule) && ! $this->isSuppressedByReading($rule),
            PmTriggerType::DATE_OR_READING => $this->isDueByDateOrReading($rule),
        };
    }

    private function isDueByDate(PmRule $rule): bool
    {
        if ($rule->last_triggered_date === null) {
            return true;
        }

        return $rule->last_triggered_date->addDays($rule->interval_days)->isPast();
    }

    private function isDueByReading(PmRule $rule): bool
    {
        $latestConfirmed = AssetMeterReading::where('asset_id', $rule->asset_id)
            ->where('usage_reading_type_id', $rule->usage_reading_type_id)
            ->whereNotNull('confirmed_at')
            ->orderByDesc('reading_at')
            ->first();

        if (! $latestConfirmed) {
            return false;
        }

        $baseline = (float) ($rule->last_triggered_reading ?? 0);
        $threshold = $baseline + (float) $rule->interval_reading;

        return (float) $latestConfirmed->reading_value >= $threshold;
    }

    private function isDueByDateOrReading(PmRule $rule): bool
    {
        $dateDue = $this->isDueByDate($rule);
        $readingDue = $this->isDueByReading($rule);

        if ($dateDue && $this->isSuppressedByDate($rule)) {
            $dateDue = false;
        }

        if ($readingDue && $this->isSuppressedByReading($rule)) {
            $readingDue = false;
        }

        return $dateDue || $readingDue;
    }

    private function isSuppressedByDate(PmRule $rule): bool
    {
        return PmOccurrenceSuppression::where('pm_rule_id', $rule->id)
            ->where('trigger_type', PmTriggerType::DATE)
            ->where('suppressed_until_date', '>=', now()->toDateString())
            ->exists();
    }

    private function isSuppressedByReading(PmRule $rule): bool
    {
        $latestConfirmed = AssetMeterReading::where('asset_id', $rule->asset_id)
            ->where('usage_reading_type_id', $rule->usage_reading_type_id)
            ->whereNotNull('confirmed_at')
            ->orderByDesc('reading_at')
            ->value('reading_value');

        if ($latestConfirmed === null) {
            return false;
        }

        return PmOccurrenceSuppression::where('pm_rule_id', $rule->id)
            ->where('trigger_type', PmTriggerType::READING)
            ->whereRaw('suppressed_until_reading >= ?', [(float) $latestConfirmed])
            ->exists();
    }

    public function isTriggeredByDate(PmRule $rule): bool
    {
        return match ($rule->trigger_type) {
            PmTriggerType::DATE => true,
            PmTriggerType::READING => false,
            PmTriggerType::DATE_OR_READING => $this->isDueByDate($rule) && ! $this->isSuppressedByDate($rule),
        };
    }

    public function isTriggeredByReading(PmRule $rule): bool
    {
        return match ($rule->trigger_type) {
            PmTriggerType::DATE => false,
            PmTriggerType::READING => true,
            PmTriggerType::DATE_OR_READING => $this->isDueByReading($rule) && ! $this->isSuppressedByReading($rule),
        };
    }
}
