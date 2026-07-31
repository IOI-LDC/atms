<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderFormFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_template_field_id' => $this->form_template_field_id,
            'uuid' => $this->uuid,
            'label' => $this->label,
            'field_type' => $this->field_type?->value,
            'has_pre_post' => $this->has_pre_post,
            'unit' => $this->unit,
            'is_required' => $this->is_required,
            'sort_order' => $this->sort_order,
            'pre_value' => $this->pre_value,
            'post_value' => $this->post_value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
