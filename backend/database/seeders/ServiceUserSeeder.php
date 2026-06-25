<?php

namespace Database\Seeders;

use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::where('code', RoleCode::SERVICE)->firstOrFail();

        User::firstOrCreate(
            ['email' => 'service@atms.internal'],
            [
                'name' => 'ATMS Service Account',
                'password' => bcrypt(Str::random(32)),
                'role_id' => $role->id,
                'is_active' => true,
                'activated_at' => now(),
            ]
        );
    }
}
