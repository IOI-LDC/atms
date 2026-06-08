<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $isTech = $user->hasRole(RoleCode::TECHNICIAN);
        $isLogistics = $user->hasRole(RoleCode::LOGISTICS);
        $isRequester = $user->hasRole(RoleCode::REQUESTER);
        $isViewer = $user->hasRole(RoleCode::VIEWER);
        $isOwn = $this->created_by === $user->id;

        $showCreatedBy = $isAdmin || $isManager || $isTech || ($isRequester && $isOwn) || $isViewer;
        $showCreatedByEmail = $isAdmin || $isManager;
        $showReviewedBy = $isAdmin || $isManager || $isViewer;
        $showPmFields = $isAdmin || $isManager || $isViewer;
        $showWorkOrder = $isAdmin || $isManager || $isTech || ($isRequester && $isOwn) || $isViewer;
        $showAttachments = $isAdmin || $isManager || $isTech || ($isRequester && $isOwn);

        $data = [
            'id' => $this->id,
            'number' => $this->number,
            'type' => $this->type,
            'status' => $this->status?->value,
            'priority' => $this->priority,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset?->id,
                'name' => $this->asset?->name,
                'erp_asset_code' => $this->asset?->erp_asset_code,
            ]),
        ];

        if ($showCreatedBy && $this->relationLoaded('createdBy')) {
            $createdBy = [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
            ];
            if ($showCreatedByEmail) {
                $createdBy['email'] = $this->createdBy?->email;
            }
            $data['created_by'] = $createdBy;
        }

        if ($showReviewedBy && $this->relationLoaded('reviewedBy')) {
            $data['reviewed_by'] = [
                'id' => $this->reviewedBy?->id,
                'name' => $this->reviewedBy?->name,
            ];
        }

        if ($this->rejection_reason && ! $isLogistics) {
            $data['rejection_reason'] = $this->rejection_reason;
        }

        if ($this->cancellation_reason && ! $isLogistics) {
            $data['cancellation_reason'] = $this->cancellation_reason;
        }

        if ($showPmFields) {
            $data['is_preventive'] = $this->is_preventive;
            $data['triggered_by_date'] = $this->triggered_by_date;
            $data['triggered_by_reading'] = $this->triggered_by_reading;
            $data['trigger_date'] = $this->trigger_date?->toDateString();
            $data['trigger_reading_value'] = $this->trigger_reading_value;
        }

        if ($showWorkOrder && $this->relationLoaded('workOrder')) {
            $data['work_order'] = $this->workOrder ? [
                'id' => $this->workOrder->id,
                'number' => $this->workOrder->number,
                'status' => $this->workOrder->status?->value,
            ] : null;
        }

        if ($showAttachments && $this->relationLoaded('attachments')) {
            $data['has_attachments'] = $this->attachments->count();
        }

        return $data;
    }
}
