<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $isTech = $user->hasRole(RoleCode::TECHNICIAN);
        $isLogistics = $user->hasRole(RoleCode::LOGISTICS);
        $isRequester = $user->hasRole(RoleCode::REQUESTER);

        $canSeeAssignee = $isAdmin || $isManager || $isTech || $isRequester;
        $canSeeEmail = $isAdmin || $isManager;
        $canSeeAssignedBy = $isAdmin || $isManager;
        $canSeeParts = $isAdmin || $isManager || $isTech || $isRequester;
        $canSeeTimestamps = $isAdmin || $isManager || $isTech || $isRequester;
        $canSeeAttachments = $isAdmin || $isManager || $isTech;
        $canSeeForm = $isAdmin || $isManager || $isTech;

        $data = [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status?->value,
            'priority' => $this->priority,
            'description' => $this->description,
            // The shared identity shape plus operational_status, which the WO
            // detail "Asset status" card needs so it reflects updates made via
            // POST /work-orders/{id}/asset-status.
            //
            // current_location carries `type` as well as the name, because the
            // page uses it for more than display: the Start button reads it to
            // decide whether the asset must be moved to a workshop/yard first
            // (see StartWorkOrder). AssetResource's location fragment is only
            // {id, name}, which is not enough for that.
            'asset' => $this->whenLoaded('asset', fn () => array_merge(
                (new AssetIdentityResource($this->asset))->toArray($request),
                [
                    'operational_status' => $this->asset?->operational_status?->value,
                    'current_location' => $this->asset?->currentLocation === null ? null : [
                        'id' => $this->asset->currentLocation->id,
                        'name' => $this->asset->currentLocation->name,
                        'code' => $this->asset->currentLocation->code,
                        'type' => $this->asset->currentLocation->type,
                    ],
                ],
            )),
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($canSeeAssignee) {
            $assignedTo = [
                'id' => $this->assignedTo?->id,
                'name' => $this->assignedTo?->name,
            ];
            if ($canSeeEmail) {
                $assignedTo['email'] = $this->assignedTo?->email;
            }
            $data['assigned_to'] = $this->whenLoaded('assignedTo', fn () => $assignedTo);
        }

        if ($canSeeAssignedBy) {
            $data['assigned_by'] = $this->whenLoaded('assignedBy', fn () => [
                'id' => $this->assignedBy?->id,
                'name' => $this->assignedBy?->name,
            ]);
        }

        if ($canSeeParts) {
            $data['parts'] = $this->whenLoaded('parts', fn () => WorkOrderPartResource::collection($this->parts));
        }

        if ($canSeeTimestamps) {
            $data['started_at'] = $this->started_at?->toIso8601String();
            $data['completed_at'] = $this->completed_at?->toIso8601String();
            $data['completion_notes'] = $this->completion_notes;
            $data['closed_at'] = $this->closed_at?->toIso8601String();
            $data['cancelled_at'] = $this->cancelled_at?->toIso8601String();
            $data['cancellation_reason'] = $this->cancellation_reason;
        }

        if ($canSeeAttachments) {
            $data['has_attachments'] = $this->whenLoaded('attachments', fn () => $this->attachments->count());
        }

        if ($canSeeForm) {
            $data['form'] = $this->whenLoaded('workOrderForm', fn () => new WorkOrderFormResource($this->workOrderForm));
        }

        // Expose the linked MR's failure classification + preventive flag so
        // the WO detail page can render the badge / close-override prompt
        // without a second fetch. Only corrective MRs carry a meaningful
        // is_failure. `number` is included so the "Related maintenance request"
        // link can display the MR number; `type` is omitted (derivable from
        // is_preventive).
        $data['maintenance_request'] = $this->whenLoaded('maintenanceRequest', fn () => [
            'id' => $this->maintenanceRequest?->id,
            'number' => $this->maintenanceRequest?->number,
            'is_preventive' => $this->maintenanceRequest?->is_preventive,
            'is_failure' => $this->maintenanceRequest?->is_failure,
        ]);

        // Where the meter stood when this job closed — the reference point for
        // "usage since the last repair". Only present once closed.
        $data['meter_snapshots'] = $this->whenLoaded('meterSnapshots', fn () => $this->meterSnapshots->map(fn ($s) => [
            'usage_reading_type_id' => $s->usage_reading_type_id,
            'reading_type' => $s->relationLoaded('readingType') ? [
                'id' => $s->readingType?->id,
                'name' => $s->readingType?->name,
                'unit' => $s->readingType?->unit,
            ] : null,
            'reading_value' => (float) $s->reading_value,
            'reading_at' => $s->reading_at?->toIso8601String(),
        ])->values());

        // The PM level recorded during the work, if any. Staged until close —
        // present here so the execution screen and the close dialog agree on
        // what was marked without asking twice.
        $data['pm_mark'] = $this->whenLoaded(
            'pmMark',
            fn () => $this->pmMark ? (new WorkOrderPmMarkResource($this->pmMark))->toArray($request) : null,
        );

        return $data;
    }
}
