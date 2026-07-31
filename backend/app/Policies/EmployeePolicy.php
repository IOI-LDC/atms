<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function manage(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }
}
