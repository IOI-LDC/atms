<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'fa_subclass_code' => $this->fa_subclass_code,
            'is_active' => $this->is_active,
            'fields' => $this->whenLoaded('fields', fn () => FormTemplateFieldResource::collection($this->fields)),
            'fields_count' => $this->when(isset($this->fields_count), fn () => (int) $this->fields_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
