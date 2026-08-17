<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A PM level marked as performed during a work order (RQ1).
 *
 * `maintenance_level` and `rule_name` are flattened onto the payload rather
 * than nested: every surface that renders a mark shows "what level, on which
 * schedule", and none of them needs the assignment's baselines.
 */
class WorkOrderPmMarkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'asset_pm_assignment_id' => $this->asset_pm_assignment_id,
            'maintenance_level' => $this->assetPmAssignment?->pmRule?->maintenance_level,
            'rule_name' => $this->assetPmAssignment?->pmRule?->name,
            'marked_at' => $this->marked_at?->toIso8601String(),
            'marked_by' => $this->whenLoaded('markedBy', fn () => [
                'id' => $this->markedBy?->id,
                'name' => $this->markedBy?->name,
            ]),
        ];
    }
}
