<?php

namespace App\Http\Resources;

use App\Queries\Reports\AgingBuckets;
use Illuminate\Http\Request;

/**
 * R-8 overdue-PM item. Extends MaintenanceRequestResource so role-gated
 * fields (PM trigger fields, rejection/cancellation, work_order, created_by,
 * reviewed_by, attachments) are inherited via parent::toArray() — visibility
 * is NOT hand-rolled here (D9). Appends aging fields computed from trigger_date.
 */
class OverduePmReportItemResource extends MaintenanceRequestResource
{
    public function toArray(Request $request): array
    {
        $today = now()->startOfDay();

        $data = parent::toArray($request);

        $daysOverdue = AgingBuckets::daysFrom($today, $this->trigger_date);
        $data['days_overdue'] = $daysOverdue;
        $data['bucket'] = AgingBuckets::bucket($daysOverdue);

        return $data;
    }
}
