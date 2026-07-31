<?php

namespace Tests\Feature\Reports;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThroughputReportTest extends TestCase
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

    private function createWorkOrder(array $overrides = []): WorkOrder
    {
        $asset = $this->createAsset();
        $mr = MaintenanceRequest::forceCreate([
            'asset_id' => $asset->id,
            'number' => 'MR-'.uniqid(),
            'status' => 'converted',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'created_at' => $overrides['created_at'] ?? now(),
        ]);

        return WorkOrder::forceCreate(array_merge([
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'number' => 'WO-'.uniqid(),
            'status' => WorkOrderStatus::OPEN,
            'priority' => 'medium',
            'created_at' => now(),
        ], $overrides));
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/throughput')->assertUnauthorized();
    }

    public function test_counts_work_orders_by_status(): void
    {
        $this->createWorkOrder(['status' => WorkOrderStatus::OPEN, 'created_at' => now()->subDays(5)]);
        $this->createWorkOrder([
            'status' => WorkOrderStatus::IN_PROGRESS,
            'created_at' => now()->subDays(5),
            'started_at' => now()->subDays(4),
        ]);
        $this->createWorkOrder([
            'status' => WorkOrderStatus::COMPLETED,
            'created_at' => now()->subDays(10),
            'completed_at' => now()->subDays(5),
        ]);
        $this->createWorkOrder([
            'status' => WorkOrderStatus::CLOSED,
            'created_at' => now()->subDays(15),
            'closed_at' => now()->subDays(5),
        ]);
        $this->createWorkOrder([
            'status' => WorkOrderStatus::CANCELLED,
            'created_at' => now()->subDays(20),
            'cancelled_at' => now()->subDays(5),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/throughput')->json();

        $this->assertSame(5, $json['summary']['wo_created']);
        $this->assertSame(1, $json['summary']['wo_open']);
        $this->assertSame(1, $json['summary']['wo_in_progress']);
        $this->assertSame(1, $json['summary']['wo_completed']);
        $this->assertSame(1, $json['summary']['wo_closed']);
        $this->assertSame(1, $json['summary']['wo_cancelled']);
    }

    public function test_daily_breakdown_includes_every_mr_and_wo_status(): void
    {
        $asset = $this->createAsset();
        MaintenanceRequest::forceCreate([
            'asset_id' => $asset->id,
            'number' => 'MR-PENDING-'.uniqid(),
            'status' => MaintenanceRequestStatus::PENDING_REVIEW,
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'created_at' => now(),
        ]);
        $this->createWorkOrder(['status' => WorkOrderStatus::OPEN, 'created_at' => now()]);
        $this->createWorkOrder([
            'status' => WorkOrderStatus::CANCELLED,
            'created_at' => now(),
            'cancelled_at' => now(),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/throughput')->assertOk()->json();
        $today = collect($json['data'])->firstWhere('date', now()->toDateString());

        $this->assertSame(1, $json['summary']['mr_pending_review']);
        $this->assertSame(1, $today['mr_pending_review']);
        $this->assertSame(1, $today['wo_open']);
        $this->assertSame(1, $today['wo_cancelled']);
    }

    public function test_cancelled_status_filter_applies_to_mrs_and_work_orders(): void
    {
        $asset = $this->createAsset();
        MaintenanceRequest::forceCreate([
            'asset_id' => $asset->id,
            'number' => 'MR-CANCELLED-'.uniqid(),
            'status' => MaintenanceRequestStatus::CANCELLED,
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'cancelled_at' => now(),
        ]);
        $this->createWorkOrder([
            'status' => WorkOrderStatus::CANCELLED,
            'created_at' => now(),
            'cancelled_at' => now(),
        ]);
        $this->createWorkOrder(['status' => WorkOrderStatus::OPEN, 'created_at' => now()]);

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/throughput?status=cancelled')
            ->assertOk()
            ->json();

        $this->assertSame(1, $json['summary']['mr_created']);
        $this->assertSame(1, $json['summary']['mr_cancelled']);
        $this->assertSame(1, $json['summary']['wo_created']);
        $this->assertSame(1, $json['summary']['wo_cancelled']);
    }

    public function test_cursor_pagination_traverses_daily_rows_once(): void
    {
        foreach ([1, 2, 3] as $daysAgo) {
            $this->createWorkOrder([
                'status' => WorkOrderStatus::OPEN,
                'created_at' => now()->subDays($daysAgo),
            ]);
        }

        $first = $this->actingAs($this->admin)
            ->getJson('/api/reports/throughput?per_page=2')
            ->assertOk()
            ->json();
        $second = $this->actingAs($this->admin)
            ->getJson('/api/reports/throughput?per_page=2&cursor='.$first['meta']['next_cursor'])
            ->assertOk()
            ->json();

        $dates = collect($first['data'])->merge($second['data'])->pluck('date');
        $this->assertCount(3, $dates);
        $this->assertCount(3, $dates->unique());
    }

    public function test_respects_date_window(): void
    {
        $this->createWorkOrder(['status' => WorkOrderStatus::OPEN, 'created_at' => now()->subDays(10)]);
        $this->createWorkOrder(['status' => WorkOrderStatus::OPEN, 'created_at' => now()->subDays(100)]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/throughput')->json();

        $this->assertSame(1, $json['summary']['wo_created']);
    }

    public function test_lifecycle_events_use_their_own_timestamps(): void
    {
        $asset = $this->createAsset();
        MaintenanceRequest::forceCreate([
            'asset_id' => $asset->id,
            'number' => 'MR-REJECTED-'.uniqid(),
            'status' => MaintenanceRequestStatus::REJECTED,
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'created_at' => now()->subDays(100),
            'reviewed_at' => now()->subDays(3),
        ]);
        $workOrder = $this->createWorkOrder([
            'status' => WorkOrderStatus::CLOSED,
            'created_at' => now()->subDays(100),
            'started_at' => now()->subDays(10),
            'completed_at' => now()->subDays(5),
            'closed_at' => now()->subDays(2),
        ]);
        $workOrder->maintenanceRequest()->update([
            'created_at' => now()->subDays(100),
            'reviewed_at' => now()->subDays(99),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/throughput')->assertOk()->json();

        $this->assertSame(0, $json['summary']['wo_created']);
        $this->assertSame(1, $json['summary']['wo_in_progress']);
        $this->assertSame(1, $json['summary']['wo_completed']);
        $this->assertSame(1, $json['summary']['wo_closed']);
        $this->assertSame(1, $json['summary']['mr_rejected']);

        $this->assertSame(1, collect($json['data'])->firstWhere('date', now()->subDays(5)->toDateString())['wo_completed']);
        $this->assertSame(1, collect($json['data'])->firstWhere('date', now()->subDays(2)->toDateString())['wo_closed']);
        $this->assertSame(1, collect($json['data'])->firstWhere('date', now()->subDays(3)->toDateString())['mr_rejected']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/throughput')->json();

        $this->assertSame(0, $json['summary']['wo_created']);
        $this->assertSame(0, $json['summary']['wo_open']);
        $this->assertSame(0, $json['summary']['wo_in_progress']);
        $this->assertSame(0, $json['summary']['wo_completed']);
        $this->assertSame(0, $json['summary']['wo_closed']);
        $this->assertSame(0, $json['summary']['wo_cancelled']);
        $this->assertSame([], $json['data']);
    }
}
