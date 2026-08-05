<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetMeterReadingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'usage_reading_type_id' => $this->usage_reading_type_id,
            'reading_value' => $this->reading_value,
            // What the operator typed, when they entered a delta instead of a total.
            // Null for absolutes, imports, and readings edited after entry.
            'entered_delta' => $this->entered_delta,
            'reading_at' => $this->reading_at?->toIso8601String(),
            'source' => $this->source,
            'work_order_id' => $this->work_order_id,
            'entered_by_user_id' => $this->entered_by_user_id,
            'confirmed_by_user_id' => $this->confirmed_by_user_id,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
