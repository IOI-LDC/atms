<?php

namespace Tests\Feature\Assets;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    public function test_only_administrator_manages_location_definitions(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);

        $payload = [
            'name' => 'Main Warehouse',
            'type' => 'warehouse',
            'code' => 'WH-01',
        ];

        $this->actingAs($manager)->postJson('/api/admin/master-data/locations', $payload)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/admin/master-data/locations', $payload)->assertCreated();
    }

    public function test_only_authorized_roles_update_asset_location(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        
        $asset = Asset::create([
            'erp_asset_code' => 'AST-LOC-TEST',
            'name' => 'Location Test Asset',
        ]);
        
        $location = Location::create([
            'name' => 'Site A',
            'type' => 'site',
        ]);

        $payload = ['location_id' => $location->id, 'reason' => 'deployment'];

        // Technician cannot update location
        $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/location", $payload)->assertForbidden();

        // Logistics can update
        $this->actingAs($logistics)->postJson("/api/assets/{$asset->id}/location", $payload)->assertOk();
        
        // Admin and Manager can also update (implicit from AssetPolicy)
    }

    public function test_location_update_creates_history_record(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        
        $loc1 = Location::create(['name' => 'Site 1', 'type' => 'site']);
        $loc2 = Location::create(['name' => 'Site 2', 'type' => 'site']);

        $asset = Asset::create([
            'erp_asset_code' => 'AST-LOC-HIST',
            'name' => 'History Test',
            'current_location_id' => $loc1->id,
        ]);

        $this->actingAs($logistics)->postJson("/api/assets/{$asset->id}/location", [
            'location_id' => $loc2->id,
            'reason' => 'transfer',
        ])->assertOk();

        $this->assertEquals($loc2->id, $asset->fresh()->current_location_id);
        
        $this->assertDatabaseHas('asset_location_histories', [
            'asset_id' => $asset->id,
            'from_location_id' => $loc1->id,
            'to_location_id' => $loc2->id,
            'reason' => 'transfer',
            'changed_by_user_id' => $logistics->id,
        ]);
    }
}
