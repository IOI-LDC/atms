<?php

namespace Tests\Feature\ReadModels;

use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetPmAssignmentResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createAsset(): Asset
    {
        return Asset::create(['erp_asset_code' => 'AST-'.uniqid(), 'name' => 'Asset', 'is_active' => true]);
    }

    private function createAssignment(Asset $asset, array $ruleAttrs = [], array $overrides = []): AssetPmAssignment
    {
        $rule = PmRule::create(array_merge([
            'name' => 'Rule',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $ruleAttrs));

        return AssetPmAssignment::create(array_merge([
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'is_active' => true,
            'assigned_by' => $this->admin->id,
        ], $overrides));
    }

    private function fetch(Asset $asset, AssetPmAssignment $assignment): array
    {
        return $this->actingAs($this->admin)
            ->getJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}")
            ->assertOk()
            ->json('data');
    }

    public function test_computes_next_due_date_for_date_rule(): void
    {
        $asset = $this->createAsset();
        $baseline = now()->subDays(20)->toDateString();
        $assignment = $this->createAssignment($asset, [], ['last_triggered_date' => $baseline]);

        $data = $this->fetch($asset, $assignment);

        $expected = now()->parse($baseline)->addDays(30)->toDateString();
        $this->assertEquals($expected, $data['next_due_date']);
    }

    public function test_computes_date_progress_and_status(): void
    {
        $asset = $this->createAsset();
        $assignment = $this->createAssignment($asset, [], ['last_triggered_date' => now()->subDays(27)->toDateString()]); // 27/30 = 90%

        $data = $this->fetch($asset, $assignment);

        $this->assertSame('due', $data['pm_status']);
        $this->assertGreaterThanOrEqual(80.0, $data['progress_percentage']);
    }

    public function test_shows_ok_status_for_low_progress(): void
    {
        $asset = $this->createAsset();
        $assignment = $this->createAssignment($asset, [], ['last_triggered_date' => now()->subDays(5)->toDateString()]);

        $data = $this->fetch($asset, $assignment);

        $this->assertSame('ok', $data['pm_status']);
    }

    public function test_resource_nests_rule_and_usage_reading_type(): void
    {
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Operating Hours', 'unit' => 'hours']);
        $assignment = $this->createAssignment($asset, [
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 500,
            'usage_reading_type_id' => $readingType->id,
        ], ['last_triggered_reading' => 1000]);

        $data = $this->fetch($asset, $assignment);

        $this->assertSame('Rule', $data['rule']['name']);
        $this->assertSame('Operating Hours', $data['rule']['usage_reading_type']['name']);
        $this->assertSame('hours', $data['rule']['usage_reading_type']['unit']);
        $this->assertEquals(1500.0, $data['next_due_reading']);
    }

    public function test_computes_reading_progress(): void
    {
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);
        $assignment = $this->createAssignment($asset, [
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 500,
            'usage_reading_type_id' => $readingType->id,
        ], ['last_triggered_reading' => 1000]);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 1300,
            'reading_at' => now(),
            'source' => 'manual',
            'entered_by_user_id' => $this->admin->id,
            'confirmed_by_user_id' => $this->admin->id,
            'confirmed_at' => now(),
        ]);

        $data = $this->fetch($asset, $assignment);

        $this->assertEquals(60.0, $data['progress_percentage']);
        $this->assertSame('soon', $data['pm_status']);
    }

    public function test_returns_null_progress_without_baseline(): void
    {
        $asset = $this->createAsset();
        $assignment = $this->createAssignment($asset);

        $data = $this->fetch($asset, $assignment);

        $this->assertNull($data['next_due_date']);
        $this->assertNull($data['progress_percentage']);
    }
}
