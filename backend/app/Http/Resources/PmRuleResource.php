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

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'maintenance_level' => $this->maintenance_level,
            'description' => $this->description,
            'trigger_type' => $this->trigger_type?->value,
            'is_active' => $this->is_active,
            'interval_days' => $this->interval_days,
            'interval_reading' => $this->interval_reading ? (float) $this->interval_reading : null,
            'assignments_count' => $this->when(isset($this->assignments_count), fn () => (int) $this->assignments_count),
            'usage_reading_type' => $this->whenLoaded('usageReadingType', fn () => [
                'id' => $this->usageReadingType?->id,
                'name' => $this->usageReadingType?->name,
                'unit' => $this->usageReadingType?->unit,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'assignments' => $this->whenLoaded('assignments', fn () => AssetPmAssignmentResource::collection($this->assignments)),
        ];

        if ($isAdmin || $isManager) {
            $data['created_by'] = $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ]);
        }

        return $data;
    }
}
