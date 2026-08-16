<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);

        $data = [
            'id' => $this->id,
            // The "No." column in LDC's parts workbook and the code the
            // Maintenance team actually quotes. Visible to every role (RQ4);
            // it identifies the part, unlike the sync metadata below.
            'erp_part_code' => $this->erp_part_code,
            'part_number' => $this->part_number,
            'name' => $this->name,
            'description' => $this->description,
            'unit_of_measure' => $this->unit_of_measure,
            'size' => $this->size_inches?->format(),
            'size_inches' => $this->size_inches?->canonical(),
            'maintenance_category' => $this->whenLoaded('maintenanceCategory', fn () => [
                'id' => $this->maintenanceCategory?->id,
                'code' => $this->maintenanceCategory?->code,
                'name' => $this->maintenanceCategory?->name,
            ]),
            'available_quantity' => (float) $this->available_quantity,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($isAdmin || $isManager) {
            $data['erp_status'] = $this->erp_status;
            $data['erp_last_synced_at'] = $this->erp_last_synced_at?->toIso8601String();
        }

        // Admin-only, for integration troubleshooting — see AssetResource.
        if ($isAdmin) {
            $data['erp_raw_data'] = $this->erp_raw_data;
        }

        return $data;
    }
}
