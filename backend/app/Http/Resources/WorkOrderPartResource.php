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
            // Plain shared identity. This used to merge `erp_part_code` on top,
            // because PartIdentityResource withheld it and the printable Part
            // Request needed it for the warehouse. RQ4 put the code in the
            // shared shape, so the merge became a no-op and is gone.
            'part' => $this->part ? new PartIdentityResource($this->part) : null,
            'quantity' => (float) $this->quantity,
            'notes' => $this->notes,
        ];
    }
}
