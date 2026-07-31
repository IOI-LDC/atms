<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderFormResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'form_template_id' => $this->form_template_id,
            'snapshotted_at' => $this->snapshotted_at?->toIso8601String(),
            'sync_dismissed_at' => $this->sync_dismissed_at?->toIso8601String(),
            'template_is_stale' => $this->templateIsStale(),
            'template' => $this->whenLoaded('template', function () {
                $template = [
                    'id' => $this->template?->id,
                    'name' => $this->template?->name,
                    'updated_at' => $this->template?->updated_at?->toIso8601String(),
                ];

                // Nested relation checked manually (single-level whenLoaded
                // only), so the latest-template fields are included when eager
                // loaded for sync comparisons.
                if ($this->template && $this->template->relationLoaded('fields')) {
                    $template['fields'] = FormTemplateFieldResource::collection($this->template->fields);
                }

                return $template;
            }),
            'fields' => $this->whenLoaded('fields', fn () => WorkOrderFormFieldResource::collection($this->fields)),
        ];
    }
}
