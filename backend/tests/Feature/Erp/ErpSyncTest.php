<?php

namespace Tests\Feature\Erp;

use App\Actions\Erp\SyncAssets;
use App\Actions\Erp\SyncParts;
use App\Enums\RoleCode;
use App\Jobs\SyncErpAssetsJob;
use App\Models\Asset;
use App\Models\ErpSyncJob;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_admin_can_dispatch_sync_assets_job(): void
    {
        Queue::fake();

        $admin = $this->createAdmin();
        $tech = $this->createTechnician();

        $this->actingAs($admin)->postJson('/api/admin/erp/sync-assets')->assertOk();
        $this->actingAs($tech)->postJson('/api/admin/erp/sync-assets')->assertForbidden();

        Queue::assertPushed(SyncErpAssetsJob::class, 1);
    }

    public function test_manager_can_dispatch_sync_assets_job(): void
    {
        Queue::fake();

        $manager = $this->createManager();

        $this->actingAs($manager)->postJson('/api/admin/erp/sync-assets')->assertOk();

        Queue::assertPushed(SyncErpAssetsJob::class, 1);
    }

    public function test_sync_assets_action_upserts_and_paginates(): void
    {
        Http::fake([
            '*/api/assets*' => Http::sequence()
                ->push([
                    'data' => [
                        [
                            'id' => 1,
                            'code' => 'AST-001',
                            'name' => 'Generator',
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
                            'code' => 'AST-002',
                            'name' => 'HVAC',
                            'status' => 'inactive',
                            'updated_at' => now()->toIso8601String(),
                        ],
                    ],
                    'next_cursor' => null,
                ]),
        ]);

        $action = app(SyncAssets::class);
        $job = $action->execute();

        $this->assertEquals('success', $job->status);
        $this->assertEquals(2, $job->total_records);
        $this->assertEquals(2, $job->created_count);
        $this->assertEquals(0, $job->failed_count);

        $this->assertDatabaseHas('assets', [
            'erp_asset_code' => 'AST-001',
            'name' => 'Generator',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('assets', [
            'erp_asset_code' => 'AST-002',
            'name' => 'HVAC',
            'is_active' => false,
        ]);
    }

    public function test_sync_records_row_errors_without_aborting(): void
    {
        Http::fake([
            '*/api/assets*' => Http::response([
                'data' => [
                    [
                        'id' => 1,
                        'code' => 'AST-001',
                        'name' => 'Generator',
                        'status' => 'active',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    [
                        'id' => 2,
                        'code' => 'AST-001',
                        'name' => 'HVAC',
                        'status' => 'inactive',
                        'updated_at' => now()->toIso8601String(),
                    ],
                ],
                'next_cursor' => null,
            ]),
        ]);

        $action = app(SyncAssets::class);
        $job = $action->execute();

        $this->assertEquals('partial', $job->status);
        $this->assertEquals(2, $job->total_records);
        $this->assertEquals(1, $job->created_count);
        $this->assertEquals(1, $job->failed_count);

        $this->assertDatabaseHas('erp_sync_errors', [
            'erp_sync_job_id' => $job->id,
            'error_type' => 'row_error',
        ]);
    }

    public function test_local_operational_fields_remain_untouched_on_update(): void
    {
        Asset::create([
            'erp_asset_id' => '99',
            'erp_asset_code' => 'AST-UPDATE',
            'name' => 'Old Name',
            'operational_status' => 'out_of_service',
            'is_active' => true,
        ]);

        Http::fake([
            '*/api/assets*' => Http::response([
                'data' => [
                    [
                        'id' => 99,
                        'code' => 'AST-UPDATE',
                        'name' => 'New Name From ERP',
                        'status' => 'active',
                        'updated_at' => now()->toIso8601String(),
                    ],
                ],
                'next_cursor' => null,
            ]),
        ]);

        $action = app(SyncAssets::class);
        $action->execute();

        $asset = Asset::where('erp_asset_id', '99')->first();
        $this->assertEquals('New Name From ERP', $asset->name);
        $this->assertEquals('out_of_service', $asset->operational_status);
        $this->assertNotNull($asset->erp_raw_data);
    }

    public function test_sync_parts_action_upserts_and_paginates(): void
    {
        Http::fake([
            '*/api/parts*' => Http::sequence()
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

        $this->assertEquals('success', $job->status);
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
            'sync_type' => 'assets',
            'status' => 'success',
            'started_at' => now(),
        ]);

        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/erp/sync-jobs');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
