<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR) || $user->id === $model->id;
    }

    public function viewSelf(User $user): bool
    {
        return true;
    }

    public function manage(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function update(User $user): bool
    {
        return $this->manage($user);
    }
}
