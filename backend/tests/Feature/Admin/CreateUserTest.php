<?php

namespace Tests\Feature\Admin;

use App\Actions\Auth\ActivateUser;
use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserActivationNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateUserTest extends TestCase
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

    private function createNonAdmin(RoleCode $roleCode = RoleCode::REQUESTER): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    public static function nonAdminRoles(): array
    {
        return [
            'maintenance manager' => [RoleCode::MAINTENANCE_MANAGER],
            'technician' => [RoleCode::TECHNICIAN],
            'logistics' => [RoleCode::LOGISTICS],
            'requester' => [RoleCode::REQUESTER],
        ];
    }

    public function test_administrator_creates_user_with_ldc_domain(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        $role = Role::where('code', RoleCode::TECHNICIAN)->first();

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'John Doe',
            'email' => 'john@ldc.com.ly',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.role.code', RoleCode::TECHNICIAN->value);

        $user = User::where('email', 'john@ldc.com.ly')->first();
        $this->assertNotNull($user);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'John Doe',
            'role_id' => $role->id,
            'is_active' => false,
            'activated_at' => null,
        ]);

        $this->assertDatabaseHas('user_activation_tokens', [
            'user_id' => $user->id,
            'type' => 'activation',
        ]);

        Notification::assertSentTo($user, UserActivationNotification::class);
    }

    public function test_email_with_foreign_domain_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $role = Role::where('code', RoleCode::TECHNICIAN)->first();

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Jane Doe',
            'email' => 'foo@example.com',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_allowlist_exception_domain_is_accepted(): void
    {
        config()->set('atms.allowed_email_domains', ['ldc.com.ly', 'partner.com']);
        $admin = $this->createAdmin();
        $role = Role::where('code', RoleCode::TECHNICIAN)->first();

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Partner User',
            'email' => 'x@partner.com',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'x@partner.com']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $existing = User::factory()->create(['email' => 'existing@ldc.com.ly']);
        $role = Role::where('code', RoleCode::TECHNICIAN)->first();

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Duplicate',
            'email' => $existing->email,
            'role_id' => $role->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 2);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_administrator_cannot_create_user(RoleCode $roleCode): void
    {
        $user = $this->createNonAdmin($roleCode);
        $role = Role::where('code', RoleCode::TECHNICIAN)->first();

        $this->actingAs($user)->postJson('/api/admin/users', [
            'name' => 'Nope',
            'email' => 'nope@ldc.com.ly',
            'role_id' => $role->id,
        ])->assertForbidden();
    }

    public function test_created_user_can_activate_and_login(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        $role = Role::where('code', RoleCode::TECHNICIAN)->first();

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'John Doe',
            'email' => 'john@ldc.com.ly',
            'role_id' => $role->id,
        ])->assertStatus(201);

        $user = User::where('email', 'john@ldc.com.ly')->first();
        $token = app(ActivateUser::class)->issueToken($user);

        $this->postJson('/api/auth/activate', [
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->assertTrue($user->fresh()->is_active);

        // The authed admin request earlier switched the shared AuthManager's
        // default driver to 'sanctum' (auth:sanctum calls shouldUse()); the
        // session login endpoint needs the 'web' guard back.
        Auth::setDefaultDriver('web');

        $this->withHeaders(['Origin' => config('app.url')])
            ->postJson('/api/auth/login', [
                'email' => 'john@ldc.com.ly',
                'password' => 'newpassword123',
            ])->assertOk();
    }

    public function test_update_user_email_must_stay_in_allowed_domain(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createNonAdmin();

        $this->actingAs($admin)->patchJson("/api/admin/users/{$user->id}", [
            'email' => 'foo@example.com',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
