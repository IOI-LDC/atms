<?php

namespace App\Http\Resources;

use App\Models\AssetPmAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes one R-1 upcoming-PM row. Exposes only non-sensitive operational
 * fields (does NOT extend AssetPmAssignmentResource, which has no role
 * gating). Wraps the computed row array produced by UpcomingPmReportQuery.
 */
class UpcomingPmItemResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  array{assignment: AssetPmAssignment, next_due_date: \Illuminate\Support\Carbon, days_until_due: int, chain_status: string}  $resource
     */
    public function toArray(Request $request): array
    {
        /** @var AssetPmAssignment $assignment */
        $assignment = $this->resource['assignment'];

        return [
            'assignment_id' => $assignment->id,
            'asset' => [
                'id' => $assignment->asset?->id,
                'name' => $assignment->asset?->name,
                'asset_tag' => $assignment->asset?->asset_tag,
                'erp_asset_code' => $assignment->asset?->erp_asset_code,
            ],
            'location' => [
                'id' => $assignment->asset?->currentLocation?->id,
                'name' => $assignment->asset?->currentLocation?->name,
            ],
            'pm_rule' => [
                'id' => $assignment->pmRule?->id,
                'name' => $assignment->pmRule?->name,
            ],
            'trigger_type' => $assignment->pmRule?->trigger_type?->value,
            'next_due_date' => $this->resource['next_due_date']->toDateString(),
            'days_until_due' => $this->resource['days_until_due'],
            'chain_status' => $this->resource['chain_status'],
        ];
    }
}
