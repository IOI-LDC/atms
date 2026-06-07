<?php

namespace App\Actions\Users;

use App\Models\User;

class ReactivateUser
{
    public function execute(User $user): User
    {
        $user->update(['is_active' => true]);

        return $user;
    }
}
