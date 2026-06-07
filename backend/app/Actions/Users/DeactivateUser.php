<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateUser
{
    public function execute(User $user): User
    {
        $user->update(['is_active' => false]);
        
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        return $user;
    }
}
