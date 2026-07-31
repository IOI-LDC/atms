<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\AssetPmAssignment;
use App\Models\User;

class AssetPmAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleCode::SERVICE)
            || $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function view(User $user, AssetPmAssignment $assignment): bool
    {
        return $user->hasRole(RoleCode::SERVICE)
            || $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function deactivate(User $user, AssetPmAssignment $assignment): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function reactivate(User $user, AssetPmAssignment $assignment): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function evaluate(User $user, AssetPmAssignment $assignment): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function evaluateAll(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }
}
