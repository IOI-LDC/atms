<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class DeactivateUser
{
    public function execute(User $user): User
    {
        $logger = app(AuditLogger::class);
        $before = $user->toArray();
        $user->update(['is_active' => false]);

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        $after = $user->fresh()->toArray();
        $logger->log('user.deactivated', $user, $before, $after);

        return $user;
    }
}