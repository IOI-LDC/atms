<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\User;

class CompanySettingPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }
}
