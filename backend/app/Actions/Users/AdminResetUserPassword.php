<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class AdminResetUserPassword
{
    public function execute(User $user, string $password): User
    {
        return DB::transaction(function () use ($user, $password) {
            $logger = app(AuditLogger::class);

            $before = $user->toArray();
            $user->update(['password' => $password]);

            // Invalidate all sessions and revoke all tokens.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            $after = $user->fresh()->toArray();
            $logger->log('user.password_reset_by_admin', $user, $before, $after);

            return $user;
        });
    }
}
