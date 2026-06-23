<?php

namespace App\Policies;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Models\MaintenanceRequest;
use App\Models\User;

class MaintenanceRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        if ($user->hasRole(RoleCode::TECHNICIAN) || $user->hasRole(RoleCode::LOGISTICS)) {
            return true;
        }

        if ($user->hasRole(RoleCode::REQUESTER)) {
            return $maintenanceRequest->created_by === $user->id;
        }

        if ($user->hasRole(RoleCode::VIEWER)) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::REQUESTER);
    }

    public function update(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        // Only pending_review MRs can be updated.
        if ($maintenanceRequest->status !== MaintenanceRequestStatus::PENDING_REVIEW) {
            return false;
        }

        // Admin/Manager can update any pending MR.
        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        // Creator of a corrective MR can update their own pending MR.
        if (($user->hasRole(RoleCode::TECHNICIAN) || $user->hasRole(RoleCode::REQUESTER))
            && ! $maintenanceRequest->is_preventive) {
            return $maintenanceRequest->created_by === $user->id;
        }

        return false;
    }

    public function approve(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function reject(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function cancel(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        if ($user->hasRole(RoleCode::REQUESTER) && ! $maintenanceRequest->is_preventive) {
            return $maintenanceRequest->created_by === $user->id;
        }

        return false;
    }
}
