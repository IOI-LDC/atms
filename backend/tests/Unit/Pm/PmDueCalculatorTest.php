<?php

namespace Tests\Unit\Pm;

use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\MaintenanceRequest;
use App\Models\PmOccurrenceSuppression;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use App\Services\Pm\PmDueCalculator;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PmDueCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private PmDueCalculator $calculator;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->calculator = new PmDueCalculator;
        $this->userId = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ])->id;
    }

    private function createAsset(): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-PM-'.uniqid(),
            'name' => 'PM Test Asset',
            'is_active' => true,
        ]);
    }

    private function createAssignment(Asset $asset, array $ruleAttrs = [], array $assignmentAttrs = []): AssetPmAssignment
    {
        $rule = PmRule::create(array_merge([
            'name' => 'Monthly inspection',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->userId,
        ], $ruleAttrs));

        return AssetPmAssignment::create(array_merge([
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'is_active' => true,
            'assigned_by' => $this->userId,
        ], $assignmentAttrs));
    }

    public function test_date_trigger_is_due_when_interval_exceeded(): void
    {
        $asset = $this->createAsset();
        $assignment = $this->createAssignment($asset, [], [
            'last_triggered_date' => now()->subDays(31),
        ]);

        $this->assertTrue($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_date_trigger_not_due_within_interval(): void
    {
        $asset = $this->createAsset();
        $assignment = $this->createAssignment($asset, [], [
            'last_triggered_date' => now()->subDays(15),
        ]);

        $this->assertFalse($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_date_trigger_due_when_no_last_triggered_date(): void
    {
        $asset = $this->createAsset();
        $assignment = $this->createAssignment($asset);

        $this->assertTrue($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_reading_trigger_is_due_when_interval_exceeded(): void
    {
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $assignment = $this->createAssignment($asset, [
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
        ], [
            'last_triggered_reading' => 5000,
        ]);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 6100,
            'reading_at' => now(),
            'source' => 'user',
            'confirmed_by_user_id' => $this->userId,
            'confirmed_at' => now(),
        ]);

        $this->assertTrue($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_reading_trigger_not_due_within_interval(): void
    {
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $assignment = $this->createAssignment($asset, [
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
        ], [
            'last_triggered_reading' => 5000,
        ]);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 5500,
            'reading_at' => now(),
            'source' => 'user',
            'confirmed_by_user_id' => $this->userId,
            'confirmed_at' => now(),
        ]);

        $this->assertFalse($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_reading_trigger_ignores_unconfirmed_readings(): void
    {
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $assignment = $this->createAssignment($asset, [
            'trigger_type' => PmTriggerType::READING,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
        ], [
            'last_triggered_reading' => 5000,
        ]);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 6100,
            'reading_at' => now(),
            'source' => 'user',
            'confirmed_at' => null,
        ]);

        $this->assertFalse($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_date_or_reading_is_due_when_either_exceeded(): void
    {
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $assignment = $this->createAssignment($asset, [
            'name' => 'Whichever comes first',
            'trigger_type' => PmTriggerType::DATE_OR_READING,
            'interval_days' => 30,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
        ], [
            'last_triggered_date' => now()->subDays(31),
            'last_triggered_reading' => 5000,
        ]);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 5050,
            'reading_at' => now(),
            'source' => 'user',
            'confirmed_by_user_id' => $this->userId,
            'confirmed_at' => now(),
        ]);

        $this->assertTrue($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_date_or_reading_not_due_when_neither_exceeded(): void
    {
        $asset = $this->createAsset();
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $assignment = $this->createAssignment($asset, [
            'name' => 'Whichever comes first',
            'trigger_type' => PmTriggerType::DATE_OR_READING,
            'interval_days' => 30,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
        ], [
            'last_triggered_date' => now()->subDays(10),
            'last_triggered_reading' => 5000,
        ]);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 5100,
            'reading_at' => now(),
            'source' => 'user',
            'confirmed_by_user_id' => $this->userId,
            'confirmed_at' => now(),
        ]);

        $this->assertFalse($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_is_suppressed_blocks_due_date_trigger(): void
    {
        $asset = $this->createAsset();
        $assignment = $this->createAssignment($asset, [], [
            'last_triggered_date' => now()->subDays(31),
        ]);

        PmOccurrenceSuppression::create([
            'pm_rule_id' => $assignment->pm_rule_id,
            'asset_id' => $asset->id,
            'maintenance_request_id' => MaintenanceRequest::create([
                'number' => 'MR-SUP-'.uniqid(),
                'asset_id' => $asset->id,
                'status' => 'rejected',
                'priority' => 'medium',
                'created_by' => $this->userId,
                'is_preventive' => true,
            ])->id,
            'trigger_type' => PmTriggerType::DATE,
            'decision_type' => 'rejected',
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
            'suppressed_until_date' => now()->addDays(10),
            'decided_by' => $this->userId,
            'decided_at' => now(),
            'reason' => 'Deferred',
        ]);

        $this->assertFalse($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_expired_suppression_allows_due(): void
    {
        $asset = $this->createAsset();
        $assignment = $this->createAssignment($asset, [], [
            'last_triggered_date' => now()->subDays(31),
        ]);

        PmOccurrenceSuppression::create([
            'pm_rule_id' => $assignment->pm_rule_id,
            'asset_id' => $asset->id,
            'maintenance_request_id' => MaintenanceRequest::create([
                'number' => 'MR-SUP-'.uniqid(),
                'asset_id' => $asset->id,
                'status' => 'rejected',
                'priority' => 'medium',
                'created_by' => $this->userId,
                'is_preventive' => true,
            ])->id,
            'trigger_type' => PmTriggerType::DATE,
            'decision_type' => 'rejected',
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
            'suppressed_until_date' => now()->subDay(),
            'decided_by' => $this->userId,
            'decided_at' => now()->subDays(5),
            'reason' => 'Was deferred',
        ]);

        $this->assertTrue($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_inactive_assignment_is_not_due(): void
    {
        $asset = $this->createAsset();
        $assignment = $this->createAssignment($asset, [], [
            'last_triggered_date' => now()->subDays(31),
            'is_active' => false,
        ]);

        $this->assertFalse($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }

    public function test_active_assignment_with_inactive_template_is_not_due(): void
    {
        $asset = $this->createAsset();
        $assignment = $this->createAssignment($asset, ['is_active' => false], [
            'last_triggered_date' => now()->subDays(31),
        ]);

        $this->assertFalse($this->calculator->isDue($assignment->fresh()->load('pmRule')));
    }
}
