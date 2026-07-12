<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianWorkloadReportTest extends TestCase
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
        $mr = \App\Models\MaintenanceRequest::forceCreate([
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
        $this->getJson('/api/reports/technician-workload')->assertUnauthorized();
    }

    public function test_calculates_workload_per_technician(): void
    {
        $tech1 = $this->createUser(RoleCode::TECHNICIAN);
        $tech2 = $this->createUser(RoleCode::TECHNICIAN);

        // Tech1: 2 open, 1 in-progress, 1 completed
        $this->createWorkOrder(['assigned_to_user_id' => $tech1->id, 'status' => WorkOrderStatus::OPEN]);
        $this->createWorkOrder(['assigned_to_user_id' => $tech1->id, 'status' => WorkOrderStatus::OPEN]);
        $this->createWorkOrder(['assigned_to_user_id' => $tech1->id, 'status' => WorkOrderStatus::IN_PROGRESS]);
        $this->createWorkOrder([
            'assigned_to_user_id' => $tech1->id,
            'status' => WorkOrderStatus::COMPLETED,
            'started_at' => now()->subHours(10),
            'completed_at' => now(),
        ]);

        // Tech2: 1 open, 1 cancelled
        $this->createWorkOrder(['assigned_to_user_id' => $tech2->id, 'status' => WorkOrderStatus::OPEN]);
        $this->createWorkOrder(['assigned_to_user_id' => $tech2->id, 'status' => WorkOrderStatus::CANCELLED]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/technician-workload')->json();

        $this->assertSame(6, $json['summary']['total_work_orders']);
        $this->assertSame(3, $json['summary']['total_open']);
        $this->assertSame(1, $json['summary']['total_in_progress']);
        $this->assertSame(1, $json['summary']['total_completed']);
        $this->assertSame(1, $json['summary']['total_cancelled']);
        $this->assertCount(2, $json['items']);

        // Tech1 should have higher workload
        $tech1Item = collect($json['items'])->firstWhere('technician_id', $tech1->id);
        $this->assertSame(2, $tech1Item['open_count']);
        $this->assertSame(1, $tech1Item['in_progress_count']);
        $this->assertSame(1, $tech1Item['completed_count']);
        $this->assertNotNull($tech1Item['avg_duration_hours']);
    }

    public function test_respects_date_window(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);

        // Recent WO (within 30 days)
        $this->createWorkOrder([
            'assigned_to_user_id' => $tech->id,
            'status' => WorkOrderStatus::OPEN,
            'created_at' => now()->subDays(10),
        ]);

        // Old WO (outside 30 days)
        $this->createWorkOrder([
            'assigned_to_user_id' => $tech->id,
            'status' => WorkOrderStatus::OPEN,
            'created_at' => now()->subDays(60),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/technician-workload')->json();

        // Default 30-day window should only include recent WO
        $this->assertSame(1, $json['summary']['total_work_orders']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/technician-workload')->json();

        $this->assertSame(0, $json['summary']['total_work_orders']);
        $this->assertSame(0, $json['summary']['total_open']);
        $this->assertSame(0, $json['summary']['total_in_progress']);
        $this->assertSame(0, $json['summary']['total_completed']);
        $this->assertSame(0, $json['summary']['total_cancelled']);
        $this->assertSame([], $json['items']);
    }
}
