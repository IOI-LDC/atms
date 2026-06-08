<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAsset(): Asset
    {
        $location = Location::create(['name' => 'Test Location', 'type' => 'building']);

        return Asset::create([
            'erp_asset_id' => 'ERP-001',
            'erp_asset_code' => 'A-001',
            'name' => 'Test Asset',
            'description' => 'A test asset',
            'category' => 'HVAC',
            'serial_number' => 'SN-001',
            'model' => 'Model-X',
            'manufacturer' => 'Mfg-Co',
            'current_location_id' => $location->id,
            'operational_status' => 'operational',
            'erp_status' => 'active',
            'erp_raw_data' => ['internal' => 'data'],
            'erp_last_synced_at' => now(),
            'is_active' => true,
        ]);
    }

    public function test_admin_sees_all_asset_fields(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('erp_raw_data', $data);
        $this->assertArrayHasKey('erp_status', $data);
        $this->assertArrayHasKey('erp_last_synced_at', $data);
        $this->assertArrayHasKey('is_active', $data);
        $this->assertArrayHasKey('serial_number', $data);
    }

    public function test_manager_sees_erp_status_but_not_raw_data(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($manager)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayHasKey('erp_status', $data);
        $this->assertArrayHasKey('is_active', $data);
    }

    public function test_technician_sees_erp_reference_fields_but_not_raw_data(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $response = $this->actingAs($tech)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayNotHasKey('is_active', $data);
        $this->assertArrayHasKey('erp_status', $data);
        $this->assertArrayHasKey('erp_last_synced_at', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('erp_asset_code', $data);
        $this->assertArrayHasKey('operational_status', $data);
    }

    public function test_logistics_sees_erp_reference_fields_but_not_raw_data(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $asset = $this->createAsset();

        $response = $this->actingAs($logistics)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayNotHasKey('is_active', $data);
        $this->assertArrayHasKey('erp_status', $data);
        $this->assertArrayHasKey('erp_last_synced_at', $data);
    }

    public function test_requester_sees_basic_fields_only(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayNotHasKey('erp_status', $data);
        $this->assertArrayNotHasKey('erp_last_synced_at', $data);
        $this->assertArrayNotHasKey('is_active', $data);
    }

    public function test_viewer_sees_erp_reference_fields_but_not_raw_data(): void
    {
        $viewer = $this->createUser(RoleCode::VIEWER);
        $asset = $this->createAsset();

        $response = $this->actingAs($viewer)->getJson('/api/assets');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayNotHasKey('is_active', $data);
        $this->assertArrayHasKey('erp_status', $data);
        $this->assertArrayHasKey('erp_last_synced_at', $data);
    }

    public function test_non_admin_non_manager_only_sees_active_assets(): void
    {
        $viewer = $this->createUser(RoleCode::VIEWER);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        Asset::create([
            'erp_asset_id' => 'ERP-002',
            'erp_asset_code' => 'A-002',
            'name' => 'Active',
            'is_active' => true,
            'current_location_id' => $location->id,
        ]);
        Asset::create([
            'erp_asset_id' => 'ERP-003',
            'erp_asset_code' => 'A-003',
            'name' => 'Inactive',
            'is_active' => false,
            'current_location_id' => $location->id,
        ]);

        $response = $this->actingAs($viewer)->getJson('/api/assets');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Active', $names);
        $this->assertNotContains('Inactive', $names);
    }

    public function test_admin_sees_inactive_assets(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        Asset::create([
            'erp_asset_id' => 'ERP-004',
            'erp_asset_code' => 'A-004',
            'name' => 'Inactive',
            'is_active' => false,
            'current_location_id' => $location->id,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/assets');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Inactive', $names);
    }
}
