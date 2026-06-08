<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PmRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $showAdminFields = $isAdmin || $isManager;

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'trigger_type' => $this->trigger_type?->value,
            'is_active' => $this->is_active,
            'interval_days' => $this->interval_days,
            'interval_reading' => $this->interval_reading ? (float) $this->interval_reading : null,
            'last_triggered_date' => $this->last_triggered_date?->toDateString(),
            'last_triggered_reading' => $this->last_triggered_reading ? (float) $this->last_triggered_reading : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset?->id,
                'name' => $this->asset?->name,
                'erp_asset_code' => $this->asset?->erp_asset_code,
            ]),
        ];

        if ($showAdminFields) {
            $data['created_by'] = $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ]);
        }

        return $data;
    }
}
