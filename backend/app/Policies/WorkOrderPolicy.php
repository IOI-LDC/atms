<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return true;
    }

    public function assign(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function start(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->assigned_to_user_id === $user->id
            || $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function updateExecution(User $user, WorkOrder $workOrder): bool
    {
        if ($workOrder->status === WorkOrderStatus::CLOSED || $workOrder->status === WorkOrderStatus::CANCELLED) {
            return false;
        }

        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        if ($user->hasRole(RoleCode::TECHNICIAN) && $workOrder->assigned_to_user_id === $user->id) {
            return $workOrder->status !== WorkOrderStatus::COMPLETED;
        }

        return false;
    }

    public function complete(User $user, WorkOrder $workOrder): bool
    {
        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        if ($user->hasRole(RoleCode::TECHNICIAN) && $workOrder->assigned_to_user_id === $user->id) {
            return true;
        }

        return false;
    }

    public function close(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function cancel(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function setAssetStatus(User $user, WorkOrder $workOrder): bool
    {
        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        if ($user->hasRole(RoleCode::TECHNICIAN)) {
            return $workOrder->assigned_to_user_id === $user->id;
        }

        return false;
    }
}
