<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $isRequester = $user->hasRole(RoleCode::REQUESTER);

        $data = [
            'id' => $this->id,
            'erp_asset_code' => $this->erp_asset_code,
            'fa_subclass_code' => $this->fa_subclass_code,
            'name' => $this->name,
            'description' => $this->description,
            'serial_number' => $this->serial_number,
            'model' => $this->model,
            'manufacturer' => $this->manufacturer,
            'operational_status' => $this->operational_status,
            'maintenance_status' => $this->maintenance_status?->value,
            'maintenance_sub_status' => $this->maintenance_sub_status?->value,
            'asset_kind' => $this->asset_kind?->value,
            'is_booked' => $this->is_booked,
            'asset_tag' => $this->asset_tag,
            'parent_asset_id' => $this->parent_asset_id,
            'child_assets_count' => $this->whenCounted('childAssets'),
            'current_location' => $this->whenLoaded('currentLocation', fn () => [
                'id' => $this->currentLocation?->id,
                'name' => $this->currentLocation?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (! $isRequester) {
            $data['erp_status'] = $this->erp_status;
            $data['erp_last_synced_at'] = $this->erp_last_synced_at?->toIso8601String();
        }

        if ($isAdmin || $isManager) {
            $data['is_active'] = $this->is_active;
        }

        if ($isAdmin) {
            $data['erp_raw_data'] = $this->erp_raw_data;
        }

        return $data;
    }
}
