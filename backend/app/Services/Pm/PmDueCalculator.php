<?php

namespace App\Services\Pm;

use App\Enums\PmTriggerType;
use App\Models\AssetMeterReading;
use App\Models\PmOccurrenceSuppression;
use App\Models\PmRule;
use Illuminate\Support\Collection;

class PmDueCalculator
{
    public function isDue(PmRule $rule, ?Collection $readings = null, ?Collection $suppressions = null): bool
    {
        if (! $rule->is_active) {
            return false;
        }

        return match ($rule->trigger_type) {
            PmTriggerType::DATE => $this->isDueByDate($rule) && ! $this->isSuppressedByDate($rule, $suppressions),
            PmTriggerType::READING => $this->isDueByReading($rule, $readings) && ! $this->isSuppressedByReading($rule, $readings, $suppressions),
            PmTriggerType::DATE_OR_READING => $this->isDueByDateOrReading($rule, $readings, $suppressions),
        };
    }

    private function isDueByDate(PmRule $rule): bool
    {
        if ($rule->last_triggered_date === null) {
            return true;
        }

        return $rule->last_triggered_date->addDays($rule->interval_days)->isPast();
    }

    private function isDueByReading(PmRule $rule, ?Collection $readings = null): bool
    {
        $latestConfirmed = $readings
            ? $readings->get("{$rule->asset_id}_{$rule->usage_reading_type_id}")
            : AssetMeterReading::where('asset_id', $rule->asset_id)
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

    private function isDueByDateOrReading(PmRule $rule, ?Collection $readings = null, ?Collection $suppressions = null): bool
    {
        $dateDue = $this->isDueByDate($rule);
        $readingDue = $this->isDueByReading($rule, $readings);

        if ($dateDue && $this->isSuppressedByDate($rule, $suppressions)) {
            $dateDue = false;
        }

        if ($readingDue && $this->isSuppressedByReading($rule, $readings, $suppressions)) {
            $readingDue = false;
        }

        return $dateDue || $readingDue;
    }

    private function isSuppressedByDate(PmRule $rule, ?Collection $suppressions = null): bool
    {
        if ($suppressions !== null) {
            return $suppressions->get($rule->id.'_date', collect())
                ->contains(fn ($s) => $s['suppressed_until_date'] >= now()->toDateString());
        }

        return PmOccurrenceSuppression::where('pm_rule_id', $rule->id)
            ->where('triggered_by_date', true)
            ->where('suppressed_until_date', '>=', now()->toDateString())
            ->exists();
    }

    private function isSuppressedByReading(PmRule $rule, ?Collection $readings = null, ?Collection $suppressions = null): bool
    {
        $latestConfirmedValue = $readings
            ? optional($readings->get("{$rule->asset_id}_{$rule->usage_reading_type_id}"))->reading_value
            : AssetMeterReading::where('asset_id', $rule->asset_id)
                ->where('usage_reading_type_id', $rule->usage_reading_type_id)
                ->whereNotNull('confirmed_at')
                ->orderByDesc('reading_at')
                ->value('reading_value');

        if ($latestConfirmedValue === null) {
            return false;
        }

        if ($suppressions !== null) {
            return $suppressions->get($rule->id.'_reading', collect())
                ->contains(fn ($s) => (float) $s['suppressed_until_reading'] >= (float) $latestConfirmedValue);
        }

        return PmOccurrenceSuppression::where('pm_rule_id', $rule->id)
            ->where('triggered_by_reading', true)
            ->whereRaw('suppressed_until_reading >= ?', [(float) $latestConfirmedValue])
            ->exists();
    }

    public function isTriggeredByDate(PmRule $rule): bool
    {
        return match ($rule->trigger_type) {
            PmTriggerType::DATE => true,
            PmTriggerType::READING => false,
            PmTriggerType::DATE_OR_READING => $this->isDueByDate($rule) && ! $this->isSuppressedByDate($rule, null),
        };
    }

    public function isTriggeredByReading(PmRule $rule): bool
    {
        return match ($rule->trigger_type) {
            PmTriggerType::DATE => false,
            PmTriggerType::READING => true,
            PmTriggerType::DATE_OR_READING => $this->isDueByReading($rule, null) && ! $this->isSuppressedByReading($rule, null, null),
        };
    }
}
