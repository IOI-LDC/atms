<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\ApiClient;
use App\Models\User;

class ApiClientPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->hasRole(RoleCode::SERVICE)) {
            return true;
        }

        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function view(User $user, ApiClient $apiClient): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function delete(User $user, ApiClient $apiClient): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }
}
