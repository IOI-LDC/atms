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

class WorkOrderBacklogReportTest extends TestCase
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

    private function createMr(Asset $asset): MaintenanceRequest
    {
        return MaintenanceRequest::forceCreate([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED,
            'priority' => 'high',
            'description' => 'MR',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'created_at' => now()->startOfDay()->subDays(10),
        ]);
    }

    private function createWo(WorkOrderStatus $status, array $overrides = []): WorkOrder
    {
        $asset = $this->createAsset();
        $mr = $this->createMr($asset);

        return WorkOrder::forceCreate(array_merge([
            'number' => 'WO-'.uniqid(),
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => $status,
            'priority' => 'high',
            'assigned_to_user_id' => $this->admin->id,
            'assigned_by_user_id' => $this->admin->id,
            'created_at' => now()->startOfDay()->subDays(5),
        ], $overrides));
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/wo-backlog')->assertUnauthorized();
    }

    public function test_includes_open_and_in_progress_wos(): void
    {
        $open = $this->createWo(WorkOrderStatus::OPEN, ['created_at' => now()->startOfDay()->subDays(5)]);
        $inProgress = $this->createWo(WorkOrderStatus::IN_PROGRESS, ['created_at' => now()->startOfDay()->subDays(15)]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/wo-backlog')->json();

        $this->assertSame(2, $json['summary']['total']);
        $byId = collect($json['data'])->keyBy('id');
        $this->assertSame(5, $byId[$open->id]['age_days']);
        $this->assertSame('0-7', $byId[$open->id]['bucket']);
        $this->assertSame(15, $byId[$inProgress->id]['age_days']);
        $this->assertSame('8-30', $byId[$inProgress->id]['bucket']);
    }

    public function test_excludes_closed_completed_cancelled(): void
    {
        $this->createWo(WorkOrderStatus::CLOSED);
        $this->createWo(WorkOrderStatus::COMPLETED);
        $this->createWo(WorkOrderStatus::CANCELLED);

        // Control: an open WO.
        $this->createWo(WorkOrderStatus::OPEN);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/wo-backlog')->json();

        $this->assertSame(1, $json['summary']['total']);
    }

    public function test_status_filter_open_only(): void
    {
        $this->createWo(WorkOrderStatus::OPEN);
        $this->createWo(WorkOrderStatus::IN_PROGRESS);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/wo-backlog?status=open')->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertSame('open', $json['data'][0]['status']);
    }

    public function test_status_filter_in_progress_only(): void
    {
        $this->createWo(WorkOrderStatus::OPEN);
        $this->createWo(WorkOrderStatus::IN_PROGRESS);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/wo-backlog?status=in_progress')->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertSame('in_progress', $json['data'][0]['status']);
    }

    public function test_summary_per_bucket_and_by_priority(): void
    {
        $this->createWo(WorkOrderStatus::OPEN, [
            'priority' => 'high', 'created_at' => now()->startOfDay()->subDays(5),
        ]);
        $this->createWo(WorkOrderStatus::IN_PROGRESS, [
            'priority' => 'high', 'created_at' => now()->startOfDay()->subDays(15),
        ]);
        $this->createWo(WorkOrderStatus::OPEN, [
            'priority' => 'low', 'created_at' => now()->startOfDay()->subDays(40),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/wo-backlog')->json();

        $this->assertSame(3, $json['summary']['total']);
        $this->assertSame(
            ['0-7' => 1, '8-30' => 1, '31-90' => 1, '91+' => 0],
            $json['summary']['by_bucket']
        );
        $this->assertSame(2, $json['summary']['by_priority']['high'] ?? 0);
        $this->assertSame(1, $json['summary']['by_priority']['low'] ?? 0);
    }

    public function test_paginated_shape(): void
    {
        $this->createWo(WorkOrderStatus::OPEN);

        $this->actingAs($this->admin)->getJson('/api/reports/wo-backlog')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
                'summary' => ['total', 'by_bucket', 'by_priority'],
            ]);
    }

    public function test_multi_page_traversal_with_duplicate_created_at(): void
    {
        $createdAt = now()->startOfDay()->subDays(10);
        foreach (range(1, 5) as $i) {
            $this->createWo(WorkOrderStatus::OPEN, ['created_at' => $createdAt]);
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $url = '/api/reports/wo-backlog?per_page=2';
            if ($cursor !== null) {
                $url .= '&cursor='.urlencode($cursor);
            }
            $json = $this->actingAs($this->admin)->getJson($url)->json();
            foreach ($json['data'] as $item) {
                $seen[] = $item['id'];
            }
            $cursor = $json['meta']['next_cursor'] ?? null;
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen), 'Cursor traversal must not skip or repeat rows.');
    }

    public function test_age_is_positive_not_negative(): void
    {
        $wo = $this->createWo(WorkOrderStatus::OPEN, ['created_at' => now()->startOfDay()->subDays(15)]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/wo-backlog')->json();

        $this->assertSame(15, $json['data'][0]['age_days']);
        $this->assertSame('8-30', $json['data'][0]['bucket']);
    }

    public function test_logistics_cannot_see_wo_assignee_and_gated_timestamps(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createWo(WorkOrderStatus::OPEN);

        $logisticsItem = $this->actingAs($logistics)->getJson('/api/reports/wo-backlog')->json('data.0');
        $adminItem = $this->actingAs($admin)->getJson('/api/reports/wo-backlog')->json('data.0');

        // Role-gated in WorkOrderResource: hidden from Logistics, shown to Admin.
        // (parts/form are not eager-loaded for this list view — detail-page fields.)
        $gated = [
            'assigned_to', 'assigned_by', 'started_at', 'completed_at', 'completion_notes',
            'closed_at', 'cancelled_at', 'cancellation_reason', 'has_attachments',
        ];

        foreach ($gated as $field) {
            $this->assertArrayNotHasKey($field, $logisticsItem, "Logistics should not see {$field}.");
        }
        foreach ($gated as $field) {
            $this->assertArrayHasKey($field, $adminItem, "Admin should see {$field}.");
        }

        // created_at + maintenance_request are always exposed (non-gated in the base resource).
        $this->assertArrayHasKey('created_at', $logisticsItem);
        $this->assertArrayHasKey('maintenance_request', $logisticsItem);
    }

    public function test_assigned_to_and_location_filters(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $locA = Location::create(['name' => 'Loc-A', 'type' => 'building']);
        $locB = Location::create(['name' => 'Loc-B', 'type' => 'building']);
        $assetA = $this->createAsset(['current_location_id' => $locA->id]);
        $assetB = $this->createAsset(['current_location_id' => $locB->id]);
        $mrA = $this->createMr($assetA);
        $mrB = $this->createMr($assetB);
        WorkOrder::forceCreate([
            'number' => 'WO-A', 'asset_id' => $assetA->id, 'maintenance_request_id' => $mrA->id,
            'status' => WorkOrderStatus::OPEN, 'priority' => 'high',
            'assigned_to_user_id' => $tech->id, 'created_at' => now()->startOfDay()->subDays(5),
        ]);
        WorkOrder::forceCreate([
            'number' => 'WO-B', 'asset_id' => $assetB->id, 'maintenance_request_id' => $mrB->id,
            'status' => WorkOrderStatus::OPEN, 'priority' => 'high',
            'assigned_to_user_id' => $this->admin->id, 'created_at' => now()->startOfDay()->subDays(5),
        ]);

        $byAssignee = $this->actingAs($this->admin)
            ->getJson('/api/reports/wo-backlog?assigned_to='.$tech->id)->json();
        $this->assertSame(1, $byAssignee['summary']['total']);

        $byLocation = $this->actingAs($this->admin)
            ->getJson('/api/reports/wo-backlog?location_id='.$locA->id)->json();
        $this->assertSame(1, $byLocation['summary']['total']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/wo-backlog')->json();

        $this->assertSame(0, $json['summary']['total']);
        $this->assertSame(
            ['0-7' => 0, '8-30' => 0, '31-90' => 0, '91+' => 0],
            $json['summary']['by_bucket']
        );
        $this->assertSame([], $json['summary']['by_priority']);
        $this->assertSame([], $json['data']);
    }
}
