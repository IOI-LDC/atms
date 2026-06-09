<?php

namespace Tests\Feature\Security;

use App\Actions\Users\DeactivateUser;
use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_unauthenticated_request_to_protected_route_returns_401(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_activation_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/activate', [
                'token' => 'invalid',
                'password' => 'password123',
            ]);
        }

        $this->postJson('/api/auth/activate', [
            'token' => 'invalid',
            'password' => 'password123',
        ])->assertStatus(429);
    }

    public function test_forgot_password_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password', [
                'email' => 'test@example.com',
            ]);
        }

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'test@example.com',
        ])->assertStatus(429);
    }

    public function test_reset_password_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/reset-password', [
                'token' => 'invalid',
                'email' => 'test@example.com',
                'password' => 'newpassword',
                'password_confirmation' => 'newpassword',
            ]);
        }

        $this->postJson('/api/auth/reset-password', [
            'token' => 'invalid',
            'email' => 'test@example.com',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])->assertStatus(429);
    }

    public function test_deactivated_user_session_is_rejected_on_login(): void
    {
        $role = Role::where('code', RoleCode::ADMINISTRATOR)->first();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        app(DeactivateUser::class)->execute($user);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(401);
    }
}
