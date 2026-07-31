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
            // The shared identity shape plus erp_part_code, which the printable
            // Part Request carries so the warehouse can look the item up in ERP.
            //
            // Merged in here rather than added to PartIdentityResource on
            // purpose: that resource backs every part dropdown, list and card,
            // and the ERP code must not reappear in any of them.
            'part' => $this->part ? array_merge(
                (new PartIdentityResource($this->part))->toArray($request),
                ['erp_part_code' => $this->part->erp_part_code],
            ) : null,
            'quantity' => (float) $this->quantity,
            'notes' => $this->notes,
        ];
    }
}
