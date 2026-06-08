<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }
}
