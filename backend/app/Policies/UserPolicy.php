<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(\App\Enums\RoleCode::ADMINISTRATOR);
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasRole(\App\Enums\RoleCode::ADMINISTRATOR) || $user->id === $model->id;
    }

    public function manage(User $user): bool
    {
        return $user->hasRole(\App\Enums\RoleCode::ADMINISTRATOR);
    }
}
