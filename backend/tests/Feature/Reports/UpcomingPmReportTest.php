<?php

namespace Tests\Feature\Reports;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\AssetPmAssignment;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpcomingPmReportTest extends TestCase
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
            'maintenance_status' => MaintenanceStatus::ENROLLED,
        ], $overrides));
    }

    private function createRule(array $overrides = []): PmRule
    {
        return PmRule::create(array_merge([
            'name' => 'Rule-'.uniqid(),
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function createAssignment(Asset $asset, PmRule $rule, array $overrides = []): AssetPmAssignment
    {
        return AssetPmAssignment::create(array_merge([
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'is_active' => true,
            'assigned_by' => $this->admin->id,
            'last_triggered_date' => now()->subDays(20)->toDateString(),
        ], $overrides));
    }

    private function createPmMr(Asset $asset, PmRule $rule, MaintenanceRequestStatus $status, array $overrides = []): MaintenanceRequest
    {
        return MaintenanceRequest::forceCreate(array_merge([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'status' => $status,
            'priority' => 'high',
            'description' => 'PM',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
            'trigger_date' => now()->toDateString(),
            'created_at' => now()->subDay(),
        ], $overrides));
    }

    private function createWo(MaintenanceRequest $mr, WorkOrderStatus $status, array $overrides = []): WorkOrder
    {
        return WorkOrder::forceCreate(array_merge([
            'number' => 'WO-'.uniqid(),
            'asset_id' => $mr->asset_id,
            'maintenance_request_id' => $mr->id,
            'status' => $status,
            'priority' => 'high',
            'created_at' => now()->subDay(),
        ], $overrides));
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/upcoming-pm')->assertUnauthorized();
    }

    public function test_includes_date_triggered_pm_due_within_horizon(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        // last_triggered -20d, interval 30 => next_due +10d (within default 30d).
        $this->createAssignment($asset, $rule);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertCount(1, $json['items']);
        $this->assertSame(10, $json['items'][0]['days_until_due']);
        $this->assertSame(
            now()->startOfDay()->addDays(10)->toDateString(),
            $json['items'][0]['next_due_date']
        );
    }

    public function test_excludes_pm_due_outside_horizon(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule(['interval_days' => 60]);
        // last_triggered -20d, interval 60 => next_due +40d (outside 30d horizon).
        $this->createAssignment($asset, $rule);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm?days=30')->json();

        $this->assertSame(0, $json['summary']['total']);
        $this->assertSame([], $json['items']);
    }

    public function test_excludes_reading_only_pm(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule(['trigger_type' => PmTriggerType::READING]);
        $this->createAssignment($asset, $rule);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame(0, $json['summary']['total']);
    }

    public function test_excludes_inactive_assignment_and_rule(): void
    {
        $assetA = $this->createAsset();
        $ruleA = $this->createRule();
        $this->createAssignment($assetA, $ruleA, ['is_active' => false]);

        $assetB = $this->createAsset();
        $ruleB = $this->createRule(['is_active' => false]);
        $this->createAssignment($assetB, $ruleB);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame(0, $json['summary']['total']);
    }

    public function test_excludes_withdrawn_asset(): void
    {
        $asset = $this->createAsset(['maintenance_status' => MaintenanceStatus::WITHDRAWN]);
        $rule = $this->createRule();
        $this->createAssignment($asset, $rule);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame(0, $json['summary']['total']);
    }

    public function test_never_triggered_is_due_now_excluded_from_upcoming(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->createAssignment($asset, $rule, ['last_triggered_date' => null]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame(0, $json['summary']['total']);
    }

    public function test_excludes_already_overdue(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        // last_triggered -35d, interval 30 => next_due -5d (past).
        $this->createAssignment($asset, $rule, ['last_triggered_date' => now()->subDays(35)->toDateString()]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame(0, $json['summary']['total']);
    }

    public function test_location_filter_applies(): void
    {
        $locA = Location::create(['name' => 'Loc-A', 'type' => 'building']);
        $locB = Location::create(['name' => 'Loc-B', 'type' => 'building']);
        $assetA = $this->createAsset(['current_location_id' => $locA->id]);
        $assetB = $this->createAsset(['current_location_id' => $locB->id]);
        $rule = $this->createRule();
        $this->createAssignment($assetA, $rule);
        $this->createAssignment($assetB, $rule);

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/upcoming-pm?location_id='.$locA->id)->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertSame($locA->id, $json['items'][0]['location']['id']);
    }

    public function test_pm_rule_filter_applies(): void
    {
        $asset = $this->createAsset();
        $ruleA = $this->createRule();
        $ruleB = $this->createRule();
        $this->createAssignment($asset, $ruleA);
        $this->createAssignment($asset, $ruleB);

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/upcoming-pm?pm_rule_id='.$ruleA->id)->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertSame($ruleA->id, $json['items'][0]['pm_rule']['id']);
    }

    public function test_chain_status_not_yet_generated(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->createAssignment($asset, $rule);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame('not_yet_generated', $json['items'][0]['chain_status']);
    }

    public function test_chain_status_generated_mr_pending(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->createAssignment($asset, $rule);
        $this->createPmMr($asset, $rule, MaintenanceRequestStatus::PENDING_REVIEW);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame('generated_mr_pending', $json['items'][0]['chain_status']);
    }

    public function test_chain_status_wo_open(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->createAssignment($asset, $rule);
        $mr = $this->createPmMr($asset, $rule, MaintenanceRequestStatus::CONVERTED);
        $this->createWo($mr, WorkOrderStatus::OPEN);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame('wo_open', $json['items'][0]['chain_status']);
    }

    public function test_chain_status_wo_completed(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->createAssignment($asset, $rule);
        $mr = $this->createPmMr($asset, $rule, MaintenanceRequestStatus::CONVERTED);
        $this->createWo($mr, WorkOrderStatus::COMPLETED);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame('wo_completed', $json['items'][0]['chain_status']);
    }

    public function test_chain_status_closed_wo_maps_to_not_yet_generated(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createRule();
        $this->createAssignment($asset, $rule);
        $mr = $this->createPmMr($asset, $rule, MaintenanceRequestStatus::CONVERTED);
        $this->createWo($mr, WorkOrderStatus::CLOSED, [
            'closed_at' => now()->subDay(),
            'closed_by_user_id' => $this->admin->id,
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame('not_yet_generated', $json['items'][0]['chain_status']);
    }

    public function test_chain_status_no_n_plus_1(): void
    {
        $rule = $this->createRule();
        foreach (range(1, 5) as $i) {
            $this->createAssignment($this->createAsset(), $rule);
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm?days=30')->json();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bulk chain resolution is constant regardless of assignment count.
        // The per-assignment hasActiveChain() N+1 pattern would issue 2 queries
        // per assignment (10 here) on top of the base ~4 — well over 12.
        $this->assertLessThan(12, $count, "Expected bounded query count, got {$count}");
    }

    public function test_summary_counts_by_trigger_type_and_due_week(): void
    {
        $ruleDate = $this->createRule();
        $ruleMixed = $this->createRule(['trigger_type' => PmTriggerType::DATE_OR_READING]);

        // next_due +3d, +12d, +21d — 9-day spacing guarantees distinct ISO weeks.
        $this->createAssignment(
            $this->createAsset(),
            $ruleDate,
            ['last_triggered_date' => now()->subDays(27)->toDateString()]
        );
        $this->createAssignment(
            $this->createAsset(),
            $ruleDate,
            ['last_triggered_date' => now()->subDays(18)->toDateString()]
        );
        $this->createAssignment(
            $this->createAsset(),
            $ruleMixed,
            ['last_triggered_date' => now()->subDays(9)->toDateString()]
        );

        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm?days=30')->json();

        $this->assertSame(3, $json['summary']['total']);
        $this->assertSame(
            ['date' => 2, 'date_or_reading' => 1],
            $json['summary']['by_trigger_type']
        );
        $this->assertSame(
            [
                now()->startOfDay()->addDays(3)->format('o-\WW') => 1,
                now()->startOfDay()->addDays(12)->format('o-\WW') => 1,
                now()->startOfDay()->addDays(21)->format('o-\WW') => 1,
            ],
            $json['summary']['by_due_week']
        );
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/upcoming-pm')->json();

        $this->assertSame(0, $json['summary']['total']);
        $this->assertSame([], $json['summary']['by_trigger_type']);
        $this->assertSame([], $json['summary']['by_due_week']);
        $this->assertSame([], $json['items']);
    }
}
