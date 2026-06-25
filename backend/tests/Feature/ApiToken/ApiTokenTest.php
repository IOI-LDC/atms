<?php

namespace Tests\Feature\ApiToken;

use App\Enums\RoleCode;
use App\Models\ApiClient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ServiceUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(ServiceUserSeeder::class);
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createApiClient(array $abilities = ['read']): array
    {
        $rawSecret = \Illuminate\Support\Str::random(64);
        $client = \App\Models\ApiClient::create([
            'name' => 'Test Client',
            'client_id' => \Illuminate\Support\Str::random(64),
            'client_secret_hash' => \Illuminate\Support\Facades\Hash::make($rawSecret),
            'abilities' => $abilities,
        ]);

        return [
            'client_id' => $client->client_id,
            'client_secret' => $rawSecret,
        ];
    }

    public function test_issue_token_with_valid_credentials(): void
    {
        $creds = $this->createApiClient();

        $tokenResponse = $this->postJson('/api/auth/token', [
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
        ]);

        $tokenResponse->assertOk()
            ->assertJsonStructure(['token', 'abilities'])
            ->assertJsonPath('abilities', ['read']);

        $token = $tokenResponse->json('token');

        // Make a GET request with the token to trigger middleware
        $this->getJson('/api/assets', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_issue_token_with_invalid_credentials_returns_401(): void
    {
        $this->postJson('/api/auth/token', [
            'client_id' => 'invalid-id',
            'client_secret' => 'invalid-secret',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.');
    }

    public function test_read_only_token_blocked_on_post(): void
    {
        $creds = $this->createApiClient(['read']);

        $tokenResponse = $this->postJson('/api/auth/token', [
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
        ]);

        $token = $tokenResponse->json('token');

        $this->app['auth']->guard('web')->logout();

        $this->postJson('/api/assets', [
            'name' => 'Attempted Create',
            'erp_asset_code' => 'AST-TOKEN-001',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertForbidden()
            ->assertJsonPath('message', 'This token is read-only and cannot perform mutating requests.');
    }

    public function test_spa_session_auth_never_blocked_by_token_middleware(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->postJson('/api/assets', [
            'name' => 'SPA Created Asset',
            'erp_asset_code' => 'AST-SPA-001',
        ])->assertCreated();
    }

    public function test_token_with_write_ability_allows_mutating_requests(): void
    {
        $creds = $this->createApiClient(['read', 'write']);

        $tokenResponse = $this->postJson('/api/auth/token', [
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
        ]);

        $token = $tokenResponse->json('token');

        $this->app['auth']->guard('web')->logout();

        $this->postJson('/api/assets', [
            'name' => 'Token Created Asset',
            'erp_asset_code' => 'AST-TOKEN-002',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated();
    }

    public function test_revoked_client_cannot_issue_token(): void
    {
        $creds = $this->createApiClient();
        $client = ApiClient::where('client_id', $creds['client_id'])->first();
        $client->update(['revoked_at' => now()]);

        $this->postJson('/api/auth/token', [
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
        ])->assertUnauthorized();
    }
}
