<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormResultReportItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $workOrder = $this->workOrderForm?->workOrder;
        $asset = $workOrder?->asset;

        return [
            'id' => $this->id,
            'field_uuid' => $this->uuid,
            'label' => $this->label,
            'field_type' => $this->field_type?->value,
            'has_pre_post' => $this->has_pre_post,
            'unit' => $this->unit,
            'pre_value' => $this->pre_value,
            'post_value' => $this->post_value,
            'notes' => $this->notes,
            'work_order' => $workOrder ? [
                'id' => $workOrder->id,
                'number' => $workOrder->number,
            ] : null,
            'asset' => $asset ? [
                'id' => $asset->id,
                'name' => $asset->name,
                'erp_asset_code' => $asset->erp_asset_code,
                'fa_subclass_code' => $asset->fa_subclass_code,
            ] : null,
        ];
    }
}
