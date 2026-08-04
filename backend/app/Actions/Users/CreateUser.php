<?php

namespace App\Actions\Users;

use App\Actions\Auth\ActivateUser;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserActivationNotification;
use App\Services\Audit\AuditLogger;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateUser
{
    public function __construct(private ActivateUser $activateUserAction) {}

    public function execute(string $name, string $email, Role $role): User
    {
        return DB::transaction(function () use ($name, $email, $role) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Str::random(32),
                'role_id' => $role->id,
                'is_active' => false,
            ]);

            $token = $this->activateUserAction->issueToken($user);
            $url = FrontendUrl::to('/activate?token='.$token);
            $user->notify(new UserActivationNotification($url));

            app(AuditLogger::class)->log('user.created', $user, [], $user->toArray());

            return $user;
        });
    }
}
