<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Asset $asset): bool
    {
        if ($asset->is_active) {
            return true;
        }

        return $user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function manage(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user): bool
    {
        return $this->manage($user);
    }

    public function updateLocation(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::LOGISTICS);
    }
}
