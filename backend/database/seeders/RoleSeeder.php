<?php

namespace Database\Seeders;

use App\Enums\RoleCode;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'code' => RoleCode::ADMINISTRATOR->value,
                'name' => 'Administrator',
                'description' => 'Full system access and configuration.',
            ],
            [
                'code' => RoleCode::MAINTENANCE_MANAGER->value,
                'name' => 'Maintenance Manager',
                'description' => 'Approves requests, manages work orders and PM rules.',
            ],
            [
                'code' => RoleCode::TECHNICIAN->value,
                'name' => 'Technician',
                'description' => 'Executes assigned work orders and records parts/readings.',
            ],
            [
                'code' => RoleCode::LOGISTICS->value,
                'name' => 'Logistics',
                'description' => 'Manages asset physical location changes.',
            ],
            [
                'code' => RoleCode::REQUESTER->value,
                'name' => 'Requester',
                'description' => 'Creates corrective maintenance requests.',
            ],
            [
                'code' => RoleCode::SERVICE->value,
                'name' => 'Service Account',
                'description' => 'Non-human role for M2M API tokens. Not assignable to users.',
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['code' => $role['code']], $role);
        }
    }
}
