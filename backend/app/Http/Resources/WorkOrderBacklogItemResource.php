<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use App\Queries\Reports\AgingBuckets;
use Illuminate\Http\Request;

/**
 * R-14 backlog item. Extends WorkOrderResource so role-gated fields (assignee,
 * assigned_by, timestamps, completion_notes, cancellation_reason, parts,
 * form) are inherited via parent::toArray() — visibility is NOT hand-rolled
 * here (D9). Appends aging fields computed from created_at.
 *
 * has_attachments is overridden here because the query uses withCount
 * instead of eager-loading the full attachments relation (P2-2).
 */
class WorkOrderBacklogItemResource extends WorkOrderResource
{
    public function toArray(Request $request): array
    {
        $today = now()->startOfDay();

        $data = parent::toArray($request);

        // Parent skips has_attachments when 'attachments' relation isn't loaded.
        // Re-add it from withCount, using the same role gate as the parent.
        $user = $request->user();
        $canSeeAttachments = $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN);
        if ($canSeeAttachments) {
            $data['has_attachments'] = (int) ($this->resource->attachments_count ?? 0);
        }

        $ageDays = AgingBuckets::daysFrom($today, $this->created_at);
        $data['age_days'] = $ageDays;
        $data['bucket'] = AgingBuckets::bucket($ageDays);

        return $data;
    }
}
