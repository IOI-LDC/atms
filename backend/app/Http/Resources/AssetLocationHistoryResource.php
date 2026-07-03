<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetLocationHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset?->id,
                'name' => $this->asset?->name,
                'erp_asset_code' => $this->asset?->erp_asset_code,
                'asset_tag' => $this->asset?->asset_tag,
            ]),
            'from_location' => $this->whenLoaded('fromLocation', fn () => [
                'id' => $this->fromLocation?->id,
                'name' => $this->fromLocation?->name,
            ]),
            'to_location' => $this->whenLoaded('toLocation', fn () => [
                'id' => $this->toLocation?->id,
                'name' => $this->toLocation?->name,
            ]),
            'effective_at' => $this->effective_at?->toIso8601String(),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'changed_by_user_id' => $this->changed_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
