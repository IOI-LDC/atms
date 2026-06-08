<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createNonAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::VIEWER)->first()->id,
            'is_active' => true,
        ]);
    }

    public function test_administrator_can_list_users(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createNonAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/users');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.role.code', RoleCode::ADMINISTRATOR->value);
    }

    public function test_non_administrator_cannot_list_users(): void
    {
        $user = $this->createNonAdmin();

        $this->actingAs($user)
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_administrator_can_deactivate_user(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createNonAdmin();

        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/deactivate");

        $response->assertOk();
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_administrator_cannot_deactivate_self(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$admin->id}/deactivate");

        $response->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_deactivation_invalidates_sessions(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createNonAdmin();

        DB::table('sessions')->insert([
            'id' => 'test-session-id',
            'user_id' => $user->id,
            'payload' => 'dummy',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/deactivate");

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_deactivated_user_cannot_login(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/deactivate")->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(401);
    }
}
