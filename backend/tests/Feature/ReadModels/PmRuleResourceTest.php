<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PmRuleResourceTest extends TestCase
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

    private function createPmRule(): PmRule
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_code' => 'A-001',
            'name' => 'Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ]);
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        return PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Test Rule',
            'trigger_type' => 'date',
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
    }

    public function test_admin_sees_created_by_in_pm_rules(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createPmRule();

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('created_by', $data);
    }

    public function test_manager_sees_created_by_in_pm_rules(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $this->createPmRule();

        $response = $this->actingAs($manager)->getJson('/api/pm-rules');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('created_by', $data);
    }

    public function test_admin_sees_pm_rule_basic_fields(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createPmRule();

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('trigger_type', $data);
        $this->assertArrayHasKey('is_active', $data);
        $this->assertArrayHasKey('interval_days', $data);
        $this->assertArrayHasKey('asset', $data);
        $this->assertArrayHasKey('created_at', $data);
    }

    public function test_show_returns_single_pm_rule(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $rule = $this->createPmRule();

        $response = $this->actingAs($admin)->getJson("/api/pm-rules/{$rule->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($rule->id, $data['id']);
        $this->assertArrayHasKey('created_by', $data);
    }
}
