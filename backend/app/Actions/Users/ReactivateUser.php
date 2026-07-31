<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\Audit\AuditLogger;

class ReactivateUser
{
    public function execute(User $user): User
    {
        $logger = app(AuditLogger::class);
        $before = $user->toArray();
        $user->update(['is_active' => true]);

        $after = $user->fresh()->toArray();
        $logger->log('user.reactivated', $user, $before, $after);

        return $user;
    }
}
