<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartsConsumptionReportItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'part_id' => (int) $this->part_id,
            'part_code' => $this->part_code,
            'part_name' => $this->part_name,
            'unit_of_measure' => $this->unit_of_measure,
            'fa_subclass_code' => $this->fa_subclass_code,
            'total_quantity' => (float) $this->total_quantity,
            'line_item_count' => (int) $this->line_item_count,
            'work_order_count' => (int) $this->work_order_count,
        ];
    }
}
