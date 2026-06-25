<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\FaSubclassTypeCode;
use App\Models\User;

class FaSubclassTypeCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function view(User $user, FaSubclassTypeCode $code): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function update(User $user, FaSubclassTypeCode $code): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function delete(User $user, FaSubclassTypeCode $code): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }
}
