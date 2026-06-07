<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\User;

class AssetMeterReadingPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR) 
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::REQUESTER);
    }

    public function confirm(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR) 
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN);
    }
}
