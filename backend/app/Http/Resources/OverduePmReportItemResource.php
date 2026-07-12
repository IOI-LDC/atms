<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use App\Queries\Reports\AgingBuckets;
use Illuminate\Http\Request;

/**
 * R-8 overdue-PM item. Extends MaintenanceRequestResource so role-gated
 * fields (PM trigger fields, rejection/cancellation, work_order, created_by,
 * reviewed_by) are inherited via parent::toArray() — visibility is NOT
 * hand-rolled here (D9). Appends aging fields computed from trigger_date.
 *
 * has_attachments is overridden here because the query uses withCount
 * instead of eager-loading the full attachments relation (P2-2).
 */
class OverduePmReportItemResource extends MaintenanceRequestResource
{
    public function toArray(Request $request): array
    {
        $today = now()->startOfDay();

        $data = parent::toArray($request);

        // Parent skips has_attachments when 'attachments' relation isn't loaded.
        // Re-add it from withCount, using the same role gate as the parent.
        $user = $request->user();
        $showAttachments = $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::REQUESTER);
        if ($showAttachments) {
            $data['has_attachments'] = (int) ($this->resource->attachments_count ?? 0);
        }

        $daysOverdue = AgingBuckets::daysFrom($today, $this->trigger_date);
        $data['days_overdue'] = $daysOverdue;
        $data['bucket'] = AgingBuckets::bucket($daysOverdue);

        return $data;
    }
}
