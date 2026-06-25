<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\Location;
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

    public function test_resource_returns_maintenance_level(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $rule = $this->createPmRule();
        $rule->update(['maintenance_level' => 'L2']);

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.maintenance_level', 'L2');
    }

    public function test_resource_computes_next_due_date_for_date_rule(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $rule = $this->createPmRule();
        $baseline = now()->subDays(20)->toDateString();
        $rule->update(['last_triggered_date' => $baseline]);

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $data = $response->json('data.0');
        $expected = now()->parse($baseline)->addDays(30)->toDateString();
        $this->assertEquals($expected, $data['next_due_date']);
    }

    public function test_resource_computes_date_progress_and_status(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $rule = $this->createPmRule(); // interval_days 30
        $rule->update(['last_triggered_date' => now()->subDays(27)->toDateString()]); // 27/30 = 90%

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $data = $response->json('data.0');
        $this->assertSame('due', $data['pm_status']);
        $this->assertGreaterThanOrEqual(80.0, $data['progress_percentage']);
    }

    public function test_resource_shows_ok_status_for_low_progress(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $rule = $this->createPmRule(); // interval_days 30
        $rule->update(['last_triggered_date' => now()->subDays(5)->toDateString()]); // 5/30 ~ 17%

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $data = $response->json('data.0');
        $this->assertSame('ok', $data['pm_status']);
    }

    public function test_resource_includes_usage_reading_type(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_code' => 'A-002',
            'name' => 'Reading Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ]);
        $readingType = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'hours']);

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Reading Rule',
            'trigger_type' => 'reading',
            'interval_reading' => 500,
            'usage_reading_type_id' => $readingType->id,
            'last_triggered_reading' => 1000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson("/api/pm-rules/{$rule->id}");

        $data = $response->json('data');
        $this->assertNotNull($data['usage_reading_type']);
        $this->assertSame('Operating Hours', $data['usage_reading_type']['name']);
        $this->assertSame('hours', $data['usage_reading_type']['unit']);
        $this->assertEquals(1500.0, $data['next_due_reading']);
    }

    public function test_resource_computes_reading_progress(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_code' => 'A-003',
            'name' => 'Reading Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ]);
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $rule = PmRule::create([
            'asset_id' => $asset->id,
            'name' => 'Reading Rule',
            'trigger_type' => 'reading',
            'interval_reading' => 500,
            'usage_reading_type_id' => $readingType->id,
            'last_triggered_reading' => 1000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 1300,
            'reading_at' => now(),
            'source' => 'manual',
            'entered_by_user_id' => $admin->id,
            'confirmed_by_user_id' => $admin->id,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $data = collect($response->json('data'))->firstWhere('id', $rule->id);
        $this->assertEquals(60.0, $data['progress_percentage']);
        $this->assertSame('soon', $data['pm_status']);
    }

    public function test_resource_returns_null_progress_without_baseline(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createPmRule(); // no last_triggered_date set

        $response = $this->actingAs($admin)->getJson('/api/pm-rules');

        $data = $response->json('data.0');
        $this->assertNull($data['next_due_date']);
        $this->assertNull($data['progress_percentage']);
    }
}
