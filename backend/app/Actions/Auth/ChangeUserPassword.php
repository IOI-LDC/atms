<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ChangeUserPassword
{
    public function execute(User $user, string $password): User
    {
        return DB::transaction(function () use ($user, $password) {
            $logger = app(AuditLogger::class);

            $before = $user->toArray();
            $user->update(['password' => $password]);

            // Invalidate all sessions and revoke all tokens, forcing a fresh login
            // consistent with the token-based and admin-initiated reset flows.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            $after = $user->fresh()->toArray();
            $logger->log('user.password_changed', $user, $before, $after);

            return $user;
        });
    }
}
