<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('password123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_authenticated_user_can_change_own_password(): void
    {
        $user = $this->createActiveUser();

        $response = $this->actingAs($user)->postJson('/api/auth/change-password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertFalse(Hash::check('password123', $user->fresh()->password));
    }

    public function test_change_password_audits_the_event(): void
    {
        $user = $this->createActiveUser();

        $this->actingAs($user)->postJson('/api/auth/change-password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.password_changed',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
        ]);
    }

    public function test_change_password_invalidates_all_sessions(): void
    {
        $user = $this->createActiveUser();

        \DB::table('sessions')->insert([
            'id' => 'test-session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($user)->postJson('/api/auth/change-password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertEquals(0, \DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_change_password_revokes_all_api_tokens(): void
    {
        $user = $this->createActiveUser();
        $user->createToken('test-token');

        $this->actingAs($user)->postJson('/api/auth/change-password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertEquals(0, $user->fresh()->tokens()->count());
    }

    public function test_change_password_requires_confirmation(): void
    {
        $user = $this->createActiveUser();

        $this->actingAs($user)->postJson('/api/auth/change-password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'does-not-match',
        ])->assertStatus(422);
    }

    public function test_change_password_rejects_weak_password(): void
    {
        $user = $this->createActiveUser();

        $this->actingAs($user)->postJson('/api/auth/change-password', [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    public function test_change_password_requires_authentication(): void
    {
        $this->postJson('/api/auth/change-password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(401);
    }
}
