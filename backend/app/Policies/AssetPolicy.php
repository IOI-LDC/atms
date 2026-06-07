<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\User;

class AssetPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }
}
