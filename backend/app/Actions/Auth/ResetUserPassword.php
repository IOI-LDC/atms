<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\UserActivationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResetUserPassword
{
    public function execute(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password) {
            $resetToken = UserActivationToken::where('type', 'reset')
                ->where('token_lookup', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $resetToken) {
                throw ValidationException::withMessages([
                    'token' => 'Invalid password reset token.',
                ]);
            }

            if ($resetToken->isExpired(1)) {
                $resetToken->delete();
                throw ValidationException::withMessages([
                    'token' => 'Password reset token has expired.',
                ]);
            }

            $user = $resetToken->user;
            $user->update(['password' => $password]);

            $resetToken->delete();

            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->tokens()->delete();

            return $user;
        });
    }

    public function issueToken(User $user): string
    {
        $user->activationTokens()->where('type', 'reset')->delete();

        return UserActivationToken::createForUser($user, 'reset');
    }
}
