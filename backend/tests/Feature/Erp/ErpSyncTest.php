<?php

namespace Tests\Feature\Erp;

use App\Actions\Erp\SyncParts;
use App\Enums\RoleCode;
use App\Models\ErpSyncJob;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ErpSyncTest extends TestCase
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

    private function createManager(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::MAINTENANCE_MANAGER)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createTechnician(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::TECHNICIAN)->first()->id,
            'is_active' => true,
        ]);
    }

    public function test_sync_parts_action_upserts_and_paginates(): void
    {
        config()->set('erp.oauth.token_url', 'https://login.test/oauth2/token');
        config()->set('erp.api.parts_endpoint', "Company('TEST')/items");
        config()->set('erp.api.base_url', 'https://api.test');

        Http::fake([
            'https://login.test/oauth2/token' => Http::response(['access_token' => 'test-token'], 200),
            'https://api.test/*' => Http::sequence()
                ->push([
                    'data' => [
                        [
                            'id' => 1,
                            'code' => 'PRT-001',
                            'name' => 'Belt',
                            'unit_of_measure' => 'EA',
                            'status' => 'active',
                            'updated_at' => now()->toIso8601String(),
                        ],
                    ],
                    'next_cursor' => 'cursor-123',
                ])
                ->push([
                    'data' => [
                        [
                            'id' => 2,
                            'code' => 'PRT-002',
                            'name' => 'Filter',
                            'unit_of_measure' => 'EA',
                            'status' => 'inactive',
                            'updated_at' => now()->toIso8601String(),
                        ],
                    ],
                    'next_cursor' => null,
                ]),
        ]);

        $action = app(SyncParts::class);
        $job = $action->execute();

        $this->assertEquals('success', $job->status->value);
        $this->assertEquals(2, $job->total_records);
        $this->assertEquals(2, $job->created_count);

        $this->assertDatabaseHas('parts', [
            'erp_part_code' => 'PRT-001',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('parts', [
            'erp_part_code' => 'PRT-002',
            'is_active' => false,
        ]);
    }

    public function test_can_retrieve_sync_job_history(): void
    {
        ErpSyncJob::create([
            'sync_type' => 'parts',
            'status' => 'success',
            'started_at' => now(),
        ]);

        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/erp/sync-jobs');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
