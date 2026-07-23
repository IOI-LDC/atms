<?php

namespace Tests\Feature\Dashboard;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\AssetLocationHistory;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardKpiTest extends TestCase
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
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);

        return Asset::create([
            'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/dashboard/kpis')->assertUnauthorized();
    }

    public function test_every_authenticated_role_can_view_kpis(): void
    {
        foreach ([
            RoleCode::ADMINISTRATOR,
            RoleCode::MAINTENANCE_MANAGER,
            RoleCode::TECHNICIAN,
            RoleCode::REQUESTER,
            RoleCode::LOGISTICS,
        ] as $roleCode) {
            $this->actingAs($this->createUser($roleCode))
                ->getJson('/api/dashboard/kpis')
                ->assertOk();
        }
    }

    public function test_kpi_response_structure(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $this->actingAs($admin)->getJson('/api/dashboard/kpis')->assertJsonStructure([
            'window' => ['days', 'from', 'to'],
            'kpis' => [
                'mtbf' => ['days'],
                'failure_rate' => ['failures', 'per_day'],
                'mttr' => ['hours'],
                'pm_compliance' => ['compliant', 'total', 'percentage'],
                'avg_mr_duration' => ['hours'],
                'avg_wo_duration' => ['hours'],
                'asset_health' => [
                    'availability' => ['percentage'],
                    'by_status' => ['active', 'under_maintenance', 'down', 'inactive'],
                    'total',
                ],
                'workforce' => [
                    'wo_backlog' => ['total', 'trend_pct'],
                    'completion_rate' => ['closed', 'created', 'percentage'],
                ],
            ],
            'recently_relocated_assets',
        ]);
    }

    public function test_mtbf_and_failure_rate_count_only_classified_faults(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->createCorrectiveMr($admin, $asset, 'MR-C1', now()->subDays(10));
        $this->createCorrectiveMr($admin, $asset, 'MR-C2', now()->subDays(5));
        // Preventive MR must NOT count as a failure.
        MaintenanceRequest::forceCreate([
            'number' => 'MR-P1', 'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED, 'priority' => 'high',
            'description' => 'P1', 'created_by' => $admin->id, 'is_preventive' => true,
            'created_at' => now()->subDays(3),
        ]);

        $kpis = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis');

        $this->assertSame(2, $kpis['failure_rate']['failures']);
        $this->assertEquals(45.0, $kpis['mtbf']['days']); // 90 / 2
    }

    public function test_mtbf_excludes_unclassified_and_no_fault_corrective_requests(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        // One real fault (counts).
        $this->createCorrectiveMr($admin, $asset, 'MR-FAULT', now()->subDays(5));

        // No-failure-found: corrective + is_failure = false. Must NOT count.
        MaintenanceRequest::forceCreate([
            'number' => 'MR-NOFAULT', 'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED, 'priority' => 'high',
            'description' => 'No fault found', 'created_by' => $admin->id, 'is_preventive' => false,
            'is_failure' => false,
            'created_at' => now()->subDays(4),
        ]);

        // Pending review: corrective + is_failure = null. Must NOT count
        // (unvalidated — the manager hasn't classified it yet).
        MaintenanceRequest::forceCreate([
            'number' => 'MR-PENDING', 'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::PENDING_REVIEW, 'priority' => 'high',
            'description' => 'Awaiting review', 'created_by' => $admin->id, 'is_preventive' => false,
            'is_failure' => null,
            'created_at' => now()->subDays(3),
        ]);

        $kpis = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis');

        $this->assertSame(1, $kpis['failure_rate']['failures']);
        $this->assertEquals(90.0, $kpis['mtbf']['days']); // 90 / 1
    }

    public function test_mttr_is_mean_assigned_to_closed_hours_for_corrective(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->createCorrectiveWorkOrder($admin, $asset, 'WO-1', 10, 2); // assigned -10h, closed -2h => 8h
        $this->createCorrectiveWorkOrder($admin, $asset, 'WO-2', 5, 1);  // assigned -5h, closed -1h => 4h
        // Preventive WO excluded from MTTR.
        $preventiveMr = MaintenanceRequest::forceCreate([
            'number' => 'MR-P', 'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED, 'priority' => 'high',
            'description' => 'P', 'created_by' => $admin->id, 'is_preventive' => true,
            'created_at' => now()->subDay(),
        ]);
        WorkOrder::forceCreate([
            'number' => 'WO-P', 'asset_id' => $asset->id, 'maintenance_request_id' => $preventiveMr->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high', 'assigned_to_user_id' => $admin->id,
            'assigned_at' => now()->subHours(20), 'closed_at' => now()->subHours(2),
            'closed_by_user_id' => $admin->id, 'created_at' => now()->subDay(),
        ]);

        $hours = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis.mttr.hours');

        $this->assertEquals(6.0, $hours); // (8 + 4) / 2
    }

    public function test_pm_compliance_on_time_versus_late(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();
        $triggerDate = now()->subDays(5)->toDateString();

        // On-time: closed before trigger date.
        $onTime = $this->createPmRequest($admin, $asset, 'PM-1', $triggerDate);
        $this->closeWorkOrder($admin, $asset, $onTime, now()->subDays(6)->toDateString());

        // Late: closed after trigger date.
        $late = $this->createPmRequest($admin, $asset, 'PM-2', $triggerDate);
        $this->closeWorkOrder($admin, $asset, $late, now()->subDays(3)->toDateString());

        // No work order yet -> not compliant.
        $this->createPmRequest($admin, $asset, 'PM-3', $triggerDate);

        // Reading-triggered PM excluded from the denominator.
        MaintenanceRequest::forceCreate([
            'number' => 'PM-R', 'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED, 'priority' => 'high',
            'description' => 'Reading', 'created_by' => $admin->id, 'is_preventive' => true,
            'triggered_by_date' => false, 'triggered_by_reading' => true,
            'created_at' => now()->subDay(),
        ]);

        $compliance = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis.pm_compliance');

        $this->assertSame(1, $compliance['compliant']);
        $this->assertSame(3, $compliance['total']);
        $this->assertEquals(33.3, $compliance['percentage']);
    }

    public function test_avg_mr_duration_uses_created_to_resolved(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        // created -10d, reviewed -8d => 48h.
        MaintenanceRequest::forceCreate([
            'number' => 'MR-1', 'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED, 'priority' => 'high',
            'description' => '1', 'created_by' => $admin->id, 'is_preventive' => false,
            'created_at' => now()->subDays(10), 'reviewed_at' => now()->subDays(8),
        ]);
        // created -4d, cancelled -3d => 24h.
        MaintenanceRequest::forceCreate([
            'number' => 'MR-2', 'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CANCELLED, 'priority' => 'high',
            'description' => '2', 'created_by' => $admin->id, 'is_preventive' => false,
            'created_at' => now()->subDays(4), 'cancelled_at' => now()->subDays(3),
        ]);

        $hours = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis.avg_mr_duration.hours');

        $this->assertEquals(36.0, $hours); // (48 + 24) / 2
    }

    public function test_avg_wo_duration_uses_created_to_closed(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        WorkOrder::forceCreate([
            'number' => 'WO-1', 'asset_id' => $asset->id, 'maintenance_request_id' => $this->createCorrectiveMr($admin, $asset, 'MR-1', now()->subDays(6))->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high',
            'created_at' => now()->subDays(5), 'closed_at' => now()->subDays(3), 'closed_by_user_id' => $admin->id,
        ]);
        WorkOrder::forceCreate([
            'number' => 'WO-2', 'asset_id' => $asset->id, 'maintenance_request_id' => $this->createCorrectiveMr($admin, $asset, 'MR-2', now()->subDays(3))->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high',
            'created_at' => now()->subDays(2), 'closed_at' => now()->subDays(1), 'closed_by_user_id' => $admin->id,
        ]);

        $hours = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis.avg_wo_duration.hours');

        $this->assertEquals(36.0, $hours); // (48 + 24) / 2
    }

    public function test_window_excludes_data_older_than_90_days(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->createCorrectiveMr($admin, $asset, 'MR-IN', now()->subDays(5));
        $this->createCorrectiveMr($admin, $asset, 'MR-OUT', now()->subDays(120));

        $kpis = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis');

        $this->assertSame(1, $kpis['failure_rate']['failures']);
        $this->assertEquals(90.0, $kpis['mtbf']['days']); // 90 / 1
    }

    public function test_empty_state_returns_null_scalars(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $json = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json();

        $this->assertNull($json['kpis']['mtbf']['days']);
        $this->assertNull($json['kpis']['mttr']['hours']);
        $this->assertNull($json['kpis']['pm_compliance']['percentage']);
        $this->assertNull($json['kpis']['avg_mr_duration']['hours']);
        $this->assertNull($json['kpis']['avg_wo_duration']['hours']);
        $this->assertSame(0, $json['kpis']['failure_rate']['failures']);
        $this->assertNull($json['kpis']['asset_health']['availability']['percentage']);
        $this->assertSame(0, $json['kpis']['asset_health']['total']);
        $this->assertSame(0, $json['kpis']['workforce']['wo_backlog']['total']);
        $this->assertNull($json['kpis']['workforce']['wo_backlog']['trend_pct']);
        $this->assertNull($json['kpis']['workforce']['completion_rate']['percentage']);
        $this->assertSame([], $json['recently_relocated_assets']);
    }

    public function test_recently_relocated_assets_returns_latest_five(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();
        $from = Location::create(['name' => 'From', 'type' => 'building']);
        $to = Location::create(['name' => 'To', 'type' => 'building']);

        foreach (range(1, 6) as $i) {
            AssetLocationHistory::create([
                'asset_id' => $asset->id, 'from_location_id' => $from->id, 'to_location_id' => $to->id,
                'effective_at' => now()->subDays($i), 'reason' => "Move-{$i}", 'changed_by_user_id' => $admin->id,
            ]);
        }
        // Outside the 90-day window -> excluded.
        AssetLocationHistory::create([
            'asset_id' => $asset->id, 'from_location_id' => $from->id, 'to_location_id' => $to->id,
            'effective_at' => now()->subDays(120), 'reason' => 'Old', 'changed_by_user_id' => $admin->id,
        ]);

        $relocated = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('recently_relocated_assets');

        $this->assertCount(5, $relocated);
        $this->assertSame('Move-1', $relocated[0]['reason']); // newest first
        $this->assertSame($asset->id, $relocated[0]['asset']['id']); // asset reference surfaced
        $this->assertSame($asset->name, $relocated[0]['asset']['name']);
    }

    public function test_asset_health_availability_and_status_counts(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $this->createAssetWithStatus('A-1', OperationalStatus::ACTIVE);
        $this->createAssetWithStatus('A-2', OperationalStatus::ACTIVE);
        $this->createAssetWithStatus('A-3', OperationalStatus::UNDER_MAINTENANCE);
        $this->createAssetWithStatus('A-4', OperationalStatus::DOWN);

        $health = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis.asset_health');

        $this->assertSame(4, $health['total']);
        $this->assertEquals(50.0, $health['availability']['percentage']); // 2 active / 4 total
        $this->assertSame(2, $health['by_status']['active']);
        $this->assertSame(1, $health['by_status']['under_maintenance']);
        $this->assertSame(1, $health['by_status']['down']);
        $this->assertSame(0, $health['by_status']['inactive']);
    }

    public function test_workforce_backlog_counts_open_and_in_progress(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->createWorkOrderWithStatus($admin, $asset, 'WO-OPEN', WorkOrderStatus::OPEN);
        $this->createWorkOrderWithStatus($admin, $asset, 'WO-PROG', WorkOrderStatus::IN_PROGRESS);
        $this->createWorkOrderWithStatus($admin, $asset, 'WO-COMP', WorkOrderStatus::COMPLETED, ['completed_at' => now()->subDay()]);
        $this->createWorkOrderWithStatus($admin, $asset, 'WO-CLOSED', WorkOrderStatus::CLOSED, [
            'completed_at' => now()->subDays(2), 'closed_at' => now()->subDay(),
        ]);
        $this->createWorkOrderWithStatus($admin, $asset, 'WO-CANCEL', WorkOrderStatus::CANCELLED, ['cancelled_at' => now()->subDay()]);

        $backlog = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis.workforce.wo_backlog');

        $this->assertSame(2, $backlog['total']);
    }

    public function test_workforce_backlog_trend_compares_to_window_start(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        // Created before window start, still open -> in both prior and current backlog.
        $this->createWorkOrderWithStatus($admin, $asset, 'WO-STILL-OPEN', WorkOrderStatus::OPEN, [
            'created_at' => now()->subDays(100),
        ]);
        // Created before window start, completed within window -> in prior backlog only.
        $this->createWorkOrderWithStatus($admin, $asset, 'WO-DONE', WorkOrderStatus::COMPLETED, [
            'created_at' => now()->subDays(100), 'completed_at' => now()->subDays(50),
        ]);

        $backlog = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis.workforce.wo_backlog');

        $this->assertSame(1, $backlog['total']); // only WO-STILL-OPEN is open/in-progress now
        $this->assertEquals(-50.0, $backlog['trend_pct']); // (1 - 2) / 2 * 100
    }

    public function test_workforce_completion_rate_closed_over_created_in_window(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->createWorkOrderWithStatus($admin, $asset, 'WO-C1', WorkOrderStatus::CLOSED, [
            'created_at' => now()->subDays(10), 'completed_at' => now()->subDays(6), 'closed_at' => now()->subDays(5),
        ]);
        $this->createWorkOrderWithStatus($admin, $asset, 'WO-C2', WorkOrderStatus::CLOSED, [
            'created_at' => now()->subDays(10), 'completed_at' => now()->subDays(4), 'closed_at' => now()->subDays(3),
        ]);
        $this->createWorkOrderWithStatus($admin, $asset, 'WO-OPEN', WorkOrderStatus::OPEN, ['created_at' => now()->subDays(10)]);
        $this->createWorkOrderWithStatus($admin, $asset, 'WO-CXL', WorkOrderStatus::CANCELLED, [
            'created_at' => now()->subDays(10), 'cancelled_at' => now()->subDays(2),
        ]);

        $rate = $this->actingAs($admin)->getJson('/api/dashboard/kpis')->json('kpis.workforce.completion_rate');

        $this->assertSame(4, $rate['created']);
        $this->assertSame(2, $rate['closed']);
        $this->assertEquals(50.0, $rate['percentage']); // 2 closed / 4 created
    }

    private function createCorrectiveMr(User $admin, Asset $asset, string $number, $createdAt): MaintenanceRequest
    {
        return MaintenanceRequest::forceCreate([
            'number' => $number, 'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED, 'priority' => 'high',
            'description' => $number, 'created_by' => $admin->id, 'is_preventive' => false,
            'is_failure' => true,
            'created_at' => $createdAt,
        ]);
    }

    private function createCorrectiveWorkOrder(User $admin, Asset $asset, string $number, int $assignedHoursAgo, int $closedHoursAgo): WorkOrder
    {
        $mr = $this->createCorrectiveMr($admin, $asset, "MR-{$number}", now()->subHours($assignedHoursAgo + 1));

        return WorkOrder::forceCreate([
            'number' => $number, 'asset_id' => $asset->id, 'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high', 'assigned_to_user_id' => $admin->id,
            'assigned_at' => now()->subHours($assignedHoursAgo), 'closed_at' => now()->subHours($closedHoursAgo),
            'closed_by_user_id' => $admin->id, 'created_at' => now()->subHours($assignedHoursAgo + 1),
        ]);
    }

    private function createPmRequest(User $admin, Asset $asset, string $number, string $triggerDate): MaintenanceRequest
    {
        return MaintenanceRequest::forceCreate([
            'number' => $number, 'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED, 'priority' => 'high',
            'description' => $number, 'created_by' => $admin->id, 'is_preventive' => true,
            'triggered_by_date' => true, 'triggered_by_reading' => false, 'trigger_date' => $triggerDate,
            'created_at' => now()->subDays(10),
        ]);
    }

    private function closeWorkOrder(User $admin, Asset $asset, MaintenanceRequest $mr, string $closedDate): WorkOrder
    {
        return WorkOrder::forceCreate([
            'number' => "WO-{$mr->number}", 'asset_id' => $asset->id, 'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high', 'assigned_to_user_id' => $admin->id,
            'assigned_at' => now()->subDays(20), 'closed_at' => $closedDate, 'closed_by_user_id' => $admin->id,
            'created_at' => now()->subDays(20),
        ]);
    }

    private function createAssetWithStatus(string $code, OperationalStatus $status): Asset
    {
        $location = Location::firstOrCreate(['name' => 'Loc', 'type' => 'building']);

        return Asset::create([
            'erp_asset_code' => $code, 'name' => "Asset {$code}",
            'is_active' => true, 'current_location_id' => $location->id,
            'operational_status' => $status,
        ]);
    }

    private function createWorkOrderWithStatus(User $admin, Asset $asset, string $number, WorkOrderStatus $status, array $attributes = []): WorkOrder
    {
        $createdAt = $attributes['created_at'] ?? now();

        $mr = MaintenanceRequest::forceCreate([
            'number' => "MR-{$number}", 'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED, 'priority' => 'high',
            'description' => $number, 'created_by' => $admin->id, 'is_preventive' => false,
            'created_at' => $createdAt,
        ]);

        return WorkOrder::forceCreate(array_merge([
            'number' => $number, 'asset_id' => $asset->id, 'maintenance_request_id' => $mr->id,
            'status' => $status, 'priority' => 'high', 'created_at' => $createdAt,
        ], $attributes));
    }
}
