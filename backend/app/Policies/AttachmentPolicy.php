<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\MaintenanceRequest;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;

class AttachmentPolicy
{
    public function uploadToAsset(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::LOGISTICS);
    }

    public function uploadToPart(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::LOGISTICS);
    }

    public function uploadToMaintenanceRequest(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        if ($user->hasRole(RoleCode::REQUESTER) && $maintenanceRequest->created_by === $user->id) {
            return true;
        }

        return false;
    }

    public function uploadToWorkOrder(User $user, WorkOrder $workOrder): bool
    {
        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        if ($user->hasRole(RoleCode::TECHNICIAN) && $workOrder->assigned_to_user_id === $user->id) {
            return true;
        }

        return false;
    }

    public function viewForAsset(User $user): bool
    {
        if ($user->hasRole(RoleCode::SERVICE)) {
            return true;
        }

        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::REQUESTER);
    }

    public function viewForPart(User $user): bool
    {
        if ($user->hasRole(RoleCode::SERVICE)) {
            return true;
        }

        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::REQUESTER);
    }

    public function viewForMaintenanceRequest(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        if ($user->hasRole(RoleCode::SERVICE)) {
            return true;
        }

        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        if ($user->hasRole(RoleCode::TECHNICIAN) || $user->hasRole(RoleCode::LOGISTICS)) {
            return true;
        }

        if ($user->hasRole(RoleCode::REQUESTER)) {
            return true;
        }

        return false;
    }

    public function viewForWorkOrder(User $user): bool
    {
        return true;
    }

    public function download(User $user, Attachment $attachment): bool
    {
        $parent = $attachment->attachable;

        if ($parent === null) {
            return false;
        }

        if ($parent instanceof Asset) {
            return $this->viewForAsset($user);
        }

        if ($parent instanceof Part) {
            return $this->viewForPart($user);
        }

        if ($parent instanceof MaintenanceRequest) {
            return $this->viewForMaintenanceRequest($user, $parent);
        }

        if ($parent instanceof WorkOrder) {
            return $this->viewForWorkOrder($user);
        }

        return false;
    }

    public function delete(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }
}
