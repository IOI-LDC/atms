<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\User;

class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function manage(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function updateLocation(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR) 
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER) 
            || $user->hasRole(RoleCode::LOGISTICS);
    }
}
