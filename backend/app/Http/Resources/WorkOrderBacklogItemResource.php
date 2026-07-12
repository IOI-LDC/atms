<?php

namespace App\Http\Resources;

use App\Queries\Reports\AgingBuckets;
use Illuminate\Http\Request;

/**
 * R-14 backlog item. Extends WorkOrderResource so role-gated fields (assignee,
 * assigned_by, timestamps, completion_notes, cancellation_reason, parts,
 * has_attachments, form) are inherited via parent::toArray() — visibility is
 * NOT hand-rolled here (D9). Appends aging fields computed from created_at.
 */
class WorkOrderBacklogItemResource extends WorkOrderResource
{
    public function toArray(Request $request): array
    {
        $today = now()->startOfDay();

        $data = parent::toArray($request);

        $ageDays = AgingBuckets::daysFrom($today, $this->created_at);
        $data['age_days'] = $ageDays;
        $data['bucket'] = AgingBuckets::bucket($ageDays);

        return $data;
    }
}
