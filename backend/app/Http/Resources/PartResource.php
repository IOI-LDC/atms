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
            'erp_part_code' => $this->erp_part_code,
            'name' => $this->name,
            'description' => $this->description,
            'unit_of_measure' => $this->unit_of_measure,
            'category' => $this->category,
            'available_quantity' => (float) $this->available_quantity,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($isAdmin || $isManager) {
            $data['erp_status'] = $this->erp_status;
            $data['erp_last_synced_at'] = $this->erp_last_synced_at?->toIso8601String();
        }

        if ($isAdmin) {
            $data['erp_raw_data'] = $this->erp_raw_data;
        }

        return $data;
    }
}
