<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderPartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'part' => [
                'id' => $this->part?->id,
                'name' => $this->part?->name,
                'erp_part_code' => $this->part?->erp_part_code,
                'unit_of_measure' => $this->part?->unit_of_measure,
            ],
            'quantity' => (float) $this->quantity,
            'notes' => $this->notes,
        ];
    }
}
