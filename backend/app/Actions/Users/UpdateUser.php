<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateUser
{
    /**
     * @param  array<string, mixed>  $fieldUpdates
     */
    public function execute(User $user, array $fieldUpdates): User
    {
        if (empty($fieldUpdates)) {
            return $user;
        }

        return DB::transaction(function () use ($user, $fieldUpdates) {
            $before = $user->toArray();
            $user->update($fieldUpdates);
            $after = $user->fresh()->toArray();

            app(AuditLogger::class)->log('user.updated', $user, $before, $after);

            return $user->fresh();
        });
    }
}
