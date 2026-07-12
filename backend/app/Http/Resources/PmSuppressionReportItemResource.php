<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PmSuppressionReportItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decision_type' => $this->decision_type,
            'trigger_type' => $this->trigger_type?->value,
            'triggered_by_date' => $this->triggered_by_date,
            'triggered_by_reading' => $this->triggered_by_reading,
            'trigger_date' => $this->trigger_date?->toDateString(),
            'trigger_reading_value' => $this->trigger_reading_value !== null
                ? (float) $this->trigger_reading_value
                : null,
            'trigger_reading_type' => $this->triggerReadingType ? [
                'id' => $this->triggerReadingType->id,
                'name' => $this->triggerReadingType->name,
                'unit' => $this->triggerReadingType->unit,
            ] : null,
            'suppressed_until_date' => $this->suppressed_until_date?->toDateString(),
            'suppressed_until_reading' => $this->suppressed_until_reading !== null
                ? (float) $this->suppressed_until_reading
                : null,
            'pm_rule' => [
                'id' => $this->pmRule?->id,
                'name' => $this->pmRule?->name,
            ],
            'asset' => [
                'id' => $this->asset?->id,
                'name' => $this->asset?->name,
                'erp_asset_code' => $this->asset?->erp_asset_code,
            ],
            'maintenance_request' => [
                'id' => $this->maintenanceRequest?->id,
                'number' => $this->maintenanceRequest?->number,
            ],
            'decided_by' => [
                'id' => $this->decidedBy?->id,
                'name' => $this->decidedBy?->name,
            ],
            'decided_at' => $this->decided_at?->toIso8601String(),
            'reason' => $this->reason,
        ];
    }
}
