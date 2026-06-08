<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->closed_at?->toDateString(),
            'type' => $this->maintenanceRequest?->type,
            'work_order_number' => $this->number,
            'maintenance_request_number' => $this->maintenanceRequest?->number,
            'description' => $this->description,
            'priority' => $this->priority,
            'parts_used' => $this->whenLoaded('parts', fn () => $this->parts->map(fn ($p) => [
                'part_name' => $p->part?->name,
                'quantity' => (float) $p->quantity,
            ])),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
