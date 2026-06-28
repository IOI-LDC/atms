<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
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

    public function test_login_succeeds_for_active_user(): void
    {
        $user = $this->createActiveUser();

        $response = $this->withHeaders(['Origin' => config('app.url')])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password123',
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['user']);
    }

    public function test_inactive_user_cannot_authenticate(): void
    {
        $user = $this->createActiveUser(['is_active' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_returns_csrf_cookie_not_bearer_token(): void
    {
        $user = $this->createActiveUser();

        $response = $this->withHeaders(['Origin' => config('app.url')])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password123',
            ]);

        $response->assertOk();
        $cookies = $response->headers->getCookies();
        $this->assertNotEmpty($cookies, 'Expected at least one cookie on login response.');
        $this->assertNull($response->json('token'));
    }

    public function test_logout_invalidates_session(): void
    {
        $user = $this->createActiveUser();

        $this->actingAs($user, 'web')
            ->withSession([])
            ->withHeaders(['Origin' => config('app.url')])
            ->postJson('/api/auth/logout')
            ->assertNoContent();
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = $this->createActiveUser();

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_me_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_wrong_password_fails(): void
    {
        $user = $this->createActiveUser();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_login_rate_limited(): void
    {
        $user = $this->createActiveUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong',
            ]);
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_forgot_password_queues_notification_without_logging_token(): void
    {
        Notification::fake();
        $user = $this->createActiveUser();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk();

        Notification::assertSentTo($user, PasswordResetNotification::class);
    }

    public function test_forgot_password_does_not_reveal_nonexistent_email(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertOk();
    }

    public function test_activate_sets_password_and_activates_account(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'email_verified_at' => null,
        ]);

        $token = Str::random(64);
        \DB::table('user_activation_tokens')->insert([
            'user_id' => $user->id,
            'token' => Hash::make($token),
            'token_lookup' => hash('sha256', $token),
            'type' => 'activation',
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/activate', [
            'token' => $token,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertOk();
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNotNull($user->fresh()->activated_at);
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_activation_token_expires_after_24_hours(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $token = Str::random(64);
        \DB::table('user_activation_tokens')->insert([
            'user_id' => $user->id,
            'token' => Hash::make($token),
            'token_lookup' => hash('sha256', $token),
            'type' => 'activation',
            'created_at' => now()->subHours(25),
        ]);

        $response = $this->postJson('/api/auth/activate', [
            'token' => $token,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_activation_token_is_one_time(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $token = Str::random(64);
        \DB::table('user_activation_tokens')->insert([
            'user_id' => $user->id,
            'token' => Hash::make($token),
            'token_lookup' => hash('sha256', $token),
            'type' => 'activation',
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/activate', [
            'token' => $token,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk();

        $this->postJson('/api/auth/activate', [
            'token' => $token,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertStatus(422);
    }

    public function test_reset_password_token_expires_after_60_minutes(): void
    {
        $user = $this->createActiveUser();

        $token = Str::random(64);
        \DB::table('user_activation_tokens')->insert([
            'user_id' => $user->id,
            'token' => Hash::make($token),
            'token_lookup' => hash('sha256', $token),
            'type' => 'reset',
            'created_at' => now()->subMinutes(61),
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_reset_password_works_with_valid_token(): void
    {
        $user = $this->createActiveUser();

        $token = Str::random(64);
        \DB::table('user_activation_tokens')->insert([
            'user_id' => $user->id,
            'token' => Hash::make($token),
            'token_lookup' => hash('sha256', $token),
            'type' => 'reset',
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_reset_password_invalidates_sessions(): void
    {
        $user = $this->createActiveUser();

        \DB::table('sessions')->insert([
            'id' => 'test-session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);

        $token = Str::random(64);
        \DB::table('user_activation_tokens')->insert([
            'user_id' => $user->id,
            'token' => Hash::make($token),
            'token_lookup' => hash('sha256', $token),
            'type' => 'reset',
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertEquals(0, \DB::table('sessions')->where('user_id', $user->id)->count());
    }
}
