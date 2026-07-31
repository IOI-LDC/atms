<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormTemplateFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_template_id' => $this->form_template_id,
            'uuid' => $this->uuid,
            'label' => $this->label,
            'field_type' => $this->field_type?->value,
            'has_pre_post' => $this->has_pre_post,
            'unit' => $this->unit,
            'is_required' => $this->is_required,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
