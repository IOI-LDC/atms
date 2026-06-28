<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartResourceTest extends TestCase
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

    private function createPart(array $overrides = []): Part
    {
        return Part::create(array_merge([
            'erp_part_id' => 'ERP-P001',
            'erp_part_code' => 'PC-001',
            'name' => 'Test Part',
            'description' => 'A test part',
            'unit_of_measure' => 'ea',
            'category' => 'bearing',
            'erp_status' => 'active',
            'erp_raw_data' => ['internal' => 'data'],
            'erp_last_synced_at' => now(),
            'is_active' => true,
        ], $overrides));
    }

    public function test_admin_sees_all_part_fields(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createPart();

        $response = $this->actingAs($admin)->getJson('/api/parts');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('erp_raw_data', $data);
        $this->assertArrayHasKey('erp_status', $data);
        $this->assertArrayHasKey('erp_last_synced_at', $data);
        $this->assertArrayHasKey('is_active', $data);
        $this->assertArrayHasKey('name', $data);
    }

    public function test_manager_sees_erp_status_but_not_raw_data(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $this->createPart();

        $response = $this->actingAs($manager)->getJson('/api/parts');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayHasKey('erp_status', $data);
        $this->assertArrayHasKey('erp_last_synced_at', $data);
    }

    public function test_technician_sees_basic_fields_only(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $this->createPart();

        $response = $this->actingAs($tech)->getJson('/api/parts');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayNotHasKey('erp_raw_data', $data);
        $this->assertArrayNotHasKey('erp_status', $data);
        $this->assertArrayNotHasKey('erp_last_synced_at', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('erp_part_code', $data);
    }

    public function test_non_admin_non_manager_only_sees_active_parts(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        Part::create([
            'erp_part_id' => 'ERP-P002',
            'erp_part_code' => 'PC-002',
            'name' => 'Active Part',
            'is_active' => true,
        ]);
        Part::create([
            'erp_part_id' => 'ERP-P003',
            'erp_part_code' => 'PC-003',
            'name' => 'Inactive Part',
            'is_active' => false,
        ]);

        $response = $this->actingAs($requester)->getJson('/api/parts');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Active Part', $names);
        $this->assertNotContains('Inactive Part', $names);
    }

    public function test_admin_sees_inactive_parts(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        Part::create([
            'erp_part_id' => 'ERP-P004',
            'erp_part_code' => 'PC-004',
            'name' => 'Inactive Part',
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/parts');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Inactive Part', $names);
    }

    public function test_show_returns_single_part(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $part = $this->createPart();

        $response = $this->actingAs($admin)->getJson("/api/parts/{$part->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($part->id, $data['id']);
        $this->assertArrayHasKey('erp_raw_data', $data);
    }

    public function test_search_filters_by_name(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createPart(['name' => 'Bearing 6205']);
        $this->createPart(['name' => 'Seal Kit', 'erp_part_id' => 'ERP-P005', 'erp_part_code' => 'PC-005']);

        $response = $this->actingAs($admin)->getJson('/api/parts?search=Bearing');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Bearing 6205', $names);
        $this->assertNotContains('Seal Kit', $names);
    }

    public function test_search_is_case_insensitive(): void
    {
        // Regression for case-sensitive search on PostgreSQL: plain LIKE is
        // case-sensitive there (and case-insensitive on SQLite, which masked
        // the bug). Search with differing case against mixed-case stored data.
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createPart(['name' => 'Motor Pump 12kW', 'erp_part_id' => 'ERP-MTR', 'erp_part_code' => 'PC-MTR']);
        $this->createPart(['name' => 'Seal Kit', 'erp_part_id' => 'ERP-SKL', 'erp_part_code' => 'PC-SKL']);

        // lowercase term against Title Case name.
        $lower = $this->actingAs($admin)->getJson('/api/parts?search=motor');
        $lower->assertStatus(200);
        $this->assertContains('Motor Pump 12kW', collect($lower->json('data'))->pluck('name'));

        // uppercase term against Title Case name.
        $upper = $this->actingAs($admin)->getJson('/api/parts?search=PUMP');
        $upper->assertStatus(200);
        $this->assertContains('Motor Pump 12kW', collect($upper->json('data'))->pluck('name'));

        // mixed-case term against the code column.
        $byCode = $this->actingAs($admin)->getJson('/api/parts?search=pc-mtr');
        $byCode->assertStatus(200);
        $this->assertContains('Motor Pump 12kW', collect($byCode->json('data'))->pluck('name'));
    }
}
