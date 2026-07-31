<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\MaintenanceCategory;
use App\Models\User;

class MaintenanceCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function view(User $user, MaintenanceCategory $category): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function update(User $user, MaintenanceCategory $category): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }
}
