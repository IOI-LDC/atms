<?php

namespace Tests\Feature\Authorization;

use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedRolePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_six_immutable_roles_are_seeded(): void
    {
        $rolesCount = Role::count();
        $this->assertEquals(6, $rolesCount);

        $expectedRoles = [
            RoleCode::ADMINISTRATOR->value,
            RoleCode::MAINTENANCE_MANAGER->value,
            RoleCode::TECHNICIAN->value,
            RoleCode::LOGISTICS->value,
            RoleCode::REQUESTER->value,
            RoleCode::VIEWER->value,
        ];

        foreach ($expectedRoles as $role) {
            $this->assertDatabaseHas('roles', ['code' => $role]);
        }
    }

    public function test_user_has_one_role(): void
    {
        $role = Role::where('code', RoleCode::VIEWER)->first();
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->assertInstanceOf(Role::class, $user->role);
        $this->assertEquals(RoleCode::VIEWER->value, $user->role->code->value);
    }
}
