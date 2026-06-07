<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\UserActivationToken;
use App\Notifications\UserActivationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivateUser
{
    public function execute(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password) {
            $activationToken = UserActivationToken::where('type', 'activation')
                ->lockForUpdate()
                ->get()
                ->first(fn ($t) => $t->matches($token));

            if (! $activationToken) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'token' => 'Invalid activation token.',
                ]);
            }

            if ($activationToken->isExpired(24)) {
                $activationToken->delete();
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'token' => 'Activation token has expired.',
                ]);
            }

            $user = $activationToken->user;
            $user->update([
                'password' => $password,
                'is_active' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'activated_at' => now(),
            ]);

            $activationToken->delete();

            return $user;
        });
    }

    public function issueToken(User $user): string
    {
        $user->activationTokens()->delete();
        return UserActivationToken::createForUser($user, 'activation');
    }
}
