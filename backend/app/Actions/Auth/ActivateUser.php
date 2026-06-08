<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\UserActivationToken;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateUser
{
    public function execute(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password) {
            $logger = app(AuditLogger::class);
            $activationToken = UserActivationToken::where('type', 'activation')
                ->where('token_lookup', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $activationToken) {
                throw ValidationException::withMessages([
                    'token' => 'Invalid activation token.',
                ]);
            }

            if ($activationToken->isExpired(24)) {
                $activationToken->delete();
                throw ValidationException::withMessages([
                    'token' => 'Activation token has expired.',
                ]);
            }

            $user = $activationToken->user;
            $before = $user->toArray();
            $user->update([
                'password' => $password,
                'is_active' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'activated_at' => now(),
            ]);

            $activationToken->delete();

            $after = $user->fresh()->toArray();
            $logger->log('user.activated', $user, $before, $after);

            return $user;
        });
    }

    public function issueToken(User $user): string
    {
        $user->activationTokens()->delete();

        return UserActivationToken::createForUser($user, 'activation');
    }
}