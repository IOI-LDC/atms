<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeterProgressionReportItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $previousValue = $this->previous_reading_value !== null
            ? (float) $this->previous_reading_value
            : null;
        $value = (float) $this->reading_value;

        return [
            'id' => $this->id,
            'asset' => $this->asset ? new AssetIdentityResource($this->asset) : null,
            'reading_type' => [
                'id' => $this->readingType?->id,
                'name' => $this->readingType?->name,
                'unit' => $this->readingType?->unit,
            ],
            'reading_value' => $value,
            'previous_reading_value' => $previousValue,
            'delta' => $previousValue !== null ? $value - $previousValue : null,
            'reading_at' => $this->reading_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'source' => $this->source,
        ];
    }
}
