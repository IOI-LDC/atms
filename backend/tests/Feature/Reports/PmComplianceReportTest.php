<?php

namespace Tests\Feature\Reports;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PmComplianceReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = $this->createUser(RoleCode::ADMINISTRATOR);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAsset(array $overrides = []): Asset
    {
        $location = Location::create(['name' => 'Loc-'.uniqid(), 'type' => 'building']);

        return Asset::create(array_merge([
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ], $overrides));
    }

    private function createRule(string $name): PmRule
    {
        return PmRule::create([
            'name' => $name,
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    private function createPmRequest(PmRule $rule, Asset $asset, string $number, string $triggerDate): MaintenanceRequest
    {
        return MaintenanceRequest::forceCreate([
            'number' => $number,
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'status' => MaintenanceRequestStatus::CONVERTED,
            'priority' => 'high',
            'description' => $number,
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
            'trigger_date' => $triggerDate,
            'created_at' => now()->subDays(10),
        ]);
    }

    private function closeWorkOrder(MaintenanceRequest $mr, string $closedDate): WorkOrder
    {
        return WorkOrder::forceCreate([
            'number' => 'WO-'.$mr->number,
            'asset_id' => $mr->asset_id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED,
            'priority' => 'high',
            'assigned_to_user_id' => $this->admin->id,
            'assigned_at' => now()->subDays(20),
            'closed_at' => $closedDate,
            'closed_by_user_id' => $this->admin->id,
            'created_at' => now()->subDays(20),
        ]);
    }

    private function groupByLabel(array $items, string $label): ?array
    {
        return collect($items)->firstWhere('group_label', $label);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/pm-compliance')->assertUnauthorized();
    }

    public function test_overall_compliance_and_per_rule_breakdown(): void
    {
        $ruleA = $this->createRule('Rule-A');
        $ruleB = $this->createRule('Rule-B');
        $asset = $this->createAsset();
        $triggerDate = now()->subDays(5)->toDateString();

        // Rule-A: 1 on-time + 1 late.
        $onTimeA = $this->createPmRequest($ruleA, $asset, 'PM-A1', $triggerDate);
        $this->closeWorkOrder($onTimeA, now()->subDays(6)->toDateString());
        $lateA = $this->createPmRequest($ruleA, $asset, 'PM-A2', $triggerDate);
        $this->closeWorkOrder($lateA, now()->subDays(3)->toDateString());

        // Rule-B: 1 on-time.
        $onTimeB = $this->createPmRequest($ruleB, $asset, 'PM-B1', $triggerDate);
        $this->closeWorkOrder($onTimeB, now()->subDays(6)->toDateString());

        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-compliance?group_by=rule')->json();

        $this->assertSame(2, $json['summary']['compliant']);
        $this->assertSame(3, $json['summary']['total']);
        $this->assertEquals(66.7, $json['summary']['percentage']);

        $groupA = $this->groupByLabel($json['items'], 'Rule-A');
        $this->assertSame(1, $groupA['compliant']);
        $this->assertSame(2, $groupA['total']);
        $this->assertEquals(50.0, $groupA['percentage']);

        $groupB = $this->groupByLabel($json['items'], 'Rule-B');
        $this->assertSame(1, $groupB['compliant']);
        $this->assertSame(1, $groupB['total']);
        $this->assertEquals(100.0, $groupB['percentage']);
    }

    public function test_group_by_asset(): void
    {
        $rule = $this->createRule('Rule-A');
        $assetA = $this->createAsset(['name' => 'Asset-A']);
        $assetB = $this->createAsset(['name' => 'Asset-B']);
        $triggerDate = now()->subDays(5)->toDateString();

        $onTime = $this->createPmRequest($rule, $assetA, 'PM-1', $triggerDate);
        $this->closeWorkOrder($onTime, now()->subDays(6)->toDateString());
        $late = $this->createPmRequest($rule, $assetB, 'PM-2', $triggerDate);
        $this->closeWorkOrder($late, now()->subDays(3)->toDateString());

        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-compliance?group_by=asset')->json();

        $groupA = $this->groupByLabel($json['items'], 'Asset-A');
        $this->assertSame(1, $groupA['compliant']);
        $this->assertSame(1, $groupA['total']);
        $this->assertEquals(100.0, $groupA['percentage']);

        $groupB = $this->groupByLabel($json['items'], 'Asset-B');
        $this->assertSame(0, $groupB['compliant']);
        $this->assertSame(1, $groupB['total']);
        $this->assertEquals(0.0, $groupB['percentage']);
    }

    public function test_group_by_location(): void
    {
        $rule = $this->createRule('Rule-A');
        $locA = Location::create(['name' => 'Loc-A', 'type' => 'building']);
        $locB = Location::create(['name' => 'Loc-B', 'type' => 'building']);
        $assetA = $this->createAsset(['name' => 'Asset-A', 'current_location_id' => $locA->id]);
        $assetB = $this->createAsset(['name' => 'Asset-B', 'current_location_id' => $locB->id]);
        $triggerDate = now()->subDays(5)->toDateString();

        $onTime = $this->createPmRequest($rule, $assetA, 'PM-1', $triggerDate);
        $this->closeWorkOrder($onTime, now()->subDays(6)->toDateString());
        $late = $this->createPmRequest($rule, $assetB, 'PM-2', $triggerDate);
        $this->closeWorkOrder($late, now()->subDays(3)->toDateString());

        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-compliance?group_by=location')->json();

        $groupA = $this->groupByLabel($json['items'], 'Loc-A');
        $this->assertSame(1, $groupA['compliant']);
        $this->assertSame(1, $groupA['total']);

        $groupB = $this->groupByLabel($json['items'], 'Loc-B');
        $this->assertSame(0, $groupB['compliant']);
        $this->assertSame(1, $groupB['total']);
    }

    public function test_reading_triggered_excluded_from_denominator(): void
    {
        $rule = $this->createRule('Rule-A');
        $asset = $this->createAsset();
        $triggerDate = now()->subDays(5)->toDateString();

        // Date-triggered PM (counts).
        $onTime = $this->createPmRequest($rule, $asset, 'PM-D1', $triggerDate);
        $this->closeWorkOrder($onTime, now()->subDays(6)->toDateString());

        // Reading-triggered PM (excluded from denominator).
        MaintenanceRequest::forceCreate([
            'number' => 'PM-R1', 'asset_id' => $asset->id, 'pm_rule_id' => $rule->id,
            'status' => MaintenanceRequestStatus::CONVERTED, 'priority' => 'high',
            'description' => 'Reading', 'created_by' => $this->admin->id, 'is_preventive' => true,
            'triggered_by_date' => false, 'triggered_by_reading' => true,
            'created_at' => now()->subDay(),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-compliance')->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertSame(1, $json['summary']['compliant']);
    }

    public function test_window_filter_excludes_out_of_range_trigger_dates(): void
    {
        $rule = $this->createRule('Rule-A');
        $asset = $this->createAsset();

        // In default 90d window.
        $inWindow = $this->createPmRequest($rule, $asset, 'PM-IN', now()->subDays(5)->toDateString());
        $this->closeWorkOrder($inWindow, now()->subDays(6)->toDateString());

        // Outside default 90d window.
        $outWindow = $this->createPmRequest($rule, $asset, 'PM-OUT', now()->subDays(100)->toDateString());
        $this->closeWorkOrder($outWindow, now()->subDays(101)->toDateString());

        $defaultJson = $this->actingAs($this->admin)->getJson('/api/reports/pm-compliance')->json();
        $this->assertSame(1, $defaultJson['summary']['total']);

        $wideJson = $this->actingAs($this->admin)
            ->getJson('/api/reports/pm-compliance?from='.now()->subDays(120)->toDateString())->json();
        $this->assertSame(2, $wideJson['summary']['total']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-compliance')->json();

        $this->assertSame(0, $json['summary']['compliant']);
        $this->assertSame(0, $json['summary']['total']);
        $this->assertNull($json['summary']['percentage']);
        $this->assertSame([], $json['items']);
    }

    public function test_location_filter_applies(): void
    {
        $rule = $this->createRule('Rule-A');
        $locA = Location::create(['name' => 'Loc-A', 'type' => 'building']);
        $locB = Location::create(['name' => 'Loc-B', 'type' => 'building']);
        $assetA = $this->createAsset(['current_location_id' => $locA->id]);
        $assetB = $this->createAsset(['current_location_id' => $locB->id]);
        $triggerDate = now()->subDays(5)->toDateString();

        $onTime = $this->createPmRequest($rule, $assetA, 'PM-A', $triggerDate);
        $this->closeWorkOrder($onTime, now()->subDays(6)->toDateString());
        $late = $this->createPmRequest($rule, $assetB, 'PM-B', $triggerDate);
        $this->closeWorkOrder($late, now()->subDays(3)->toDateString());

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/pm-compliance?location_id='.$locA->id)->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertSame(1, $json['summary']['compliant']);
    }

    public function test_pm_rule_filter_applies(): void
    {
        $ruleA = $this->createRule('Rule-A');
        $ruleB = $this->createRule('Rule-B');
        $asset = $this->createAsset();
        $triggerDate = now()->subDays(5)->toDateString();

        $onTimeA = $this->createPmRequest($ruleA, $asset, 'PM-A', $triggerDate);
        $this->closeWorkOrder($onTimeA, now()->subDays(6)->toDateString());
        $lateB = $this->createPmRequest($ruleB, $asset, 'PM-B', $triggerDate);
        $this->closeWorkOrder($lateB, now()->subDays(3)->toDateString());

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/pm-compliance?pm_rule_id='.$ruleA->id)->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertSame(1, $json['summary']['compliant']);
    }

    public function test_from_after_to_returns_422(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/pm-compliance?from=2026-12-01&to=2026-01-01')
            ->assertStatus(422);
    }
}
