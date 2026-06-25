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

        $data = [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status?->value,
            'priority' => $this->priority,
            'description' => $this->description,
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset?->id,
                'name' => $this->asset?->name,
                'erp_asset_code' => $this->asset?->erp_asset_code,
            ]),
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

        return $data;
    }
}
