<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetPmAssignment;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\UsageReadingType;
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

    private function createPmRule(array $overrides = []): PmRule
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        return PmRule::create(array_merge([
            'name' => 'Test Rule',
            'trigger_type' => 'date',
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $admin->id,
        ], $overrides));
    }

    private function createAsset(): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
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
        $this->assertArrayHasKey('assignments_count', $data);
        $this->assertArrayNotHasKey('asset', $data);
        $this->assertArrayNotHasKey('next_due_date', $data);
        $this->assertArrayNotHasKey('pm_status', $data);
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

    public function test_resource_returns_maintenance_level(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $rule = $this->createPmRule(['maintenance_level' => 'L2']);

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.maintenance_level', 'L2');
    }

    public function test_resource_includes_usage_reading_type_on_show(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $readingType = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'hours']);
        $rule = $this->createPmRule([
            'trigger_type' => 'reading',
            'interval_reading' => 500,
            'usage_reading_type_id' => $readingType->id,
        ]);

        $response = $this->actingAs($admin)->getJson("/api/pm-rules/{$rule->id}");

        $data = $response->json('data');
        $this->assertNotNull($data['usage_reading_type']);
        $this->assertSame('Operating Hours', $data['usage_reading_type']['name']);
        $this->assertSame('hours', $data['usage_reading_type']['unit']);
    }

    public function test_assignments_count_counts_active_assignments_only(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $rule = $this->createPmRule();
        $asset = $this->createAsset();

        AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $admin->id]);
        AssetPmAssignment::create(['asset_id' => $this->createAsset()->id, 'pm_rule_id' => $rule->id, 'is_active' => false, 'assigned_by' => $admin->id, 'deactivated_by' => $admin->id, 'deactivated_at' => now()]);

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.0.assignments_count'));
    }

    public function test_show_includes_assignments(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $rule = $this->createPmRule();
        $asset = $this->createAsset();
        AssetPmAssignment::create(['asset_id' => $asset->id, 'pm_rule_id' => $rule->id, 'is_active' => true, 'assigned_by' => $admin->id]);

        $response = $this->actingAs($admin)->getJson("/api/pm-rules/{$rule->id}");

        $response->assertStatus(200);
        $assignments = $response->json('data.assignments');
        $this->assertNotNull($assignments);
        $this->assertCount(1, $assignments);
    }
}
