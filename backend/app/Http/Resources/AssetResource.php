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
            // The ERP FA subclass, exposed under its display name. It is Asset
            // Class — a separate concept from Maintenance Category below.
            'asset_class' => $this->fa_subclass_code,
            'fa_subclass_code' => $this->fa_subclass_code,
            'name' => $this->name,
            'description' => $this->description,
            'serial_number' => $this->serial_number,
            'size' => $this->size_inches?->format(),
            'size_inches' => $this->size_inches?->canonical(),
            'maintenance_category' => $this->whenLoaded('maintenanceCategory', fn () => [
                'id' => $this->maintenanceCategory?->id,
                'code' => $this->maintenanceCategory?->code,
                'name' => $this->maintenanceCategory?->name,
            ]),
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

        // ERP identifiers are Admin-only, for integration troubleshooting. They
        // must never reach ordinary lists, dropdowns, cards, reports or printed
        // forms — hence their absence from AssetIdentityResource.
        if ($isAdmin) {
            $data['erp_asset_code'] = $this->erp_asset_code;
            $data['erp_raw_data'] = $this->erp_raw_data;
        }

        return $data;
    }
}
