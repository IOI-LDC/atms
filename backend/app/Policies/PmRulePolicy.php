<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\PmRule;
use App\Models\User;

class PmRulePolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->hasRole(RoleCode::SERVICE)) {
            return true;
        }

        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function view(User $user, PmRule $pmRule): bool
    {
        if ($user->hasRole(RoleCode::SERVICE)) {
            return true;
        }

        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function update(User $user, PmRule $pmRule): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function deactivate(User $user, PmRule $pmRule): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function reactivate(User $user, PmRule $pmRule): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function evaluate(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }
}
