<?php

namespace Database\Seeders;

use App\Enums\RoleCode;
use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(ServiceUserSeeder::class);
        $this->call(LocationSeeder::class);
        $this->call(EmployeeSeeder::class);

        CompanySetting::create([
            'timezone' => config('atms.company_timezone', 'Africa/Tripoli'),
        ]);

        $adminRole = Role::where('code', RoleCode::ADMINISTRATOR)->first();
        User::firstOrCreate(
            ['email' => 'system@atms.internal'],
            [
                'name' => 'ATMS System',
                'password' => bcrypt(Str::random(64)),
                'role_id' => $adminRole?->id,
                'is_active' => false,
            ]
        );
    }
}
