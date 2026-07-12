<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\MaintenanceRequest;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartsConsumptionReportTest extends TestCase
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

    private function createAsset(string $faSubclassCode = 'GEN'): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'ASSET-'.uniqid(),
            'name' => 'Asset',
            'fa_subclass_code' => $faSubclassCode,
            'is_active' => true,
        ]);
    }

    private function createPart(string $name, string $unit = 'EA'): Part
    {
        return Part::create([
            'erp_part_code' => 'PART-'.uniqid(),
            'name' => $name,
            'unit_of_measure' => $unit,
            'erp_status' => 'active',
            'is_active' => true,
        ]);
    }

    private function createWorkOrder(
        WorkOrderStatus $status,
        Asset $asset,
        ?\DateTimeInterface $completedAt = null,
        ?\DateTimeInterface $closedAt = null,
    ): WorkOrder {
        $maintenanceRequest = MaintenanceRequest::forceCreate([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
        ]);

        return WorkOrder::forceCreate([
            'number' => 'WO-'.uniqid(),
            'maintenance_request_id' => $maintenanceRequest->id,
            'asset_id' => $asset->id,
            'status' => $status,
            'priority' => 'medium',
            'completed_at' => $completedAt,
            'closed_at' => $closedAt,
        ]);
    }

    private function addPart(WorkOrder $workOrder, Part $part, float $quantity): WorkOrderPart
    {
        return WorkOrderPart::create([
            'work_order_id' => $workOrder->id,
            'part_id' => $part->id,
            'quantity' => $quantity,
            'added_by_user_id' => $this->admin->id,
        ]);
    }

    private function findItem(array $items, int $partId, string $faSubclassCode): ?array
    {
        return collect($items)->first(
            fn (array $item): bool => $item['part_id'] === $partId
                && $item['fa_subclass_code'] === $faSubclassCode
        );
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/reports/parts-consumption')->assertUnauthorized();
    }

    public function test_all_authenticated_roles_can_view_report(): void
    {
        foreach (RoleCode::cases() as $roleCode) {
            $this->actingAs($this->createUser($roleCode))
                ->getJson('/api/reports/parts-consumption')
                ->assertOk();
        }
    }

    public function test_aggregates_finalized_usage_by_part_and_fa_subclass(): void
    {
        $filter = $this->createPart('Oil Filter');
        $bearing = $this->createPart('Bearing');
        $generatorA = $this->createAsset('GEN');
        $generatorB = $this->createAsset('GEN');
        $pump = $this->createAsset('PUMP');

        $generatorWoA = $this->createWorkOrder(WorkOrderStatus::COMPLETED, $generatorA, now()->subDays(5));
        $generatorWoB = $this->createWorkOrder(WorkOrderStatus::CLOSED, $generatorB, now()->subDays(4), now()->subDay());
        $pumpWo = $this->createWorkOrder(WorkOrderStatus::COMPLETED, $pump, now()->subDays(3));

        $this->addPart($generatorWoA, $filter, 2.25);
        $this->addPart($generatorWoB, $filter, 3.50);
        $this->addPart($pumpWo, $filter, 1);
        $this->addPart($generatorWoA, $bearing, 4);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/parts-consumption')->json();

        $this->assertSame(4, $json['summary']['total_line_items']);
        $this->assertSame(2, $json['summary']['distinct_parts']);
        $this->assertSame(3, $json['summary']['distinct_work_orders']);
        $this->assertNull($json['summary']['total_quantity']);
        $this->assertNull($json['summary']['unit_of_measure']);

        $generatorFilters = $this->findItem($json['data'], $filter->id, 'GEN');
        $this->assertNotNull($generatorFilters);
        $this->assertSame(5.75, $generatorFilters['total_quantity']);
        $this->assertSame(2, $generatorFilters['line_item_count']);
        $this->assertSame(2, $generatorFilters['work_order_count']);
        $this->assertSame('EA', $generatorFilters['unit_of_measure']);

        $pumpFilters = $this->findItem($json['data'], $filter->id, 'PUMP');
        $this->assertEquals(1.0, $pumpFilters['total_quantity']);
    }

    public function test_excludes_non_finalized_work_orders(): void
    {
        $part = $this->createPart('Filter');
        $asset = $this->createAsset();

        foreach ([WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::CANCELLED] as $status) {
            $this->addPart($this->createWorkOrder($status, $asset), $part, 10);
        }

        $this->addPart(
            $this->createWorkOrder(WorkOrderStatus::COMPLETED, $asset, now()->subDays(2)),
            $part,
            2
        );
        $this->addPart(
            $this->createWorkOrder(WorkOrderStatus::CLOSED, $asset, now()->subDays(3), now()->subDay()),
            $part,
            3
        );

        $json = $this->actingAs($this->admin)->getJson('/api/reports/parts-consumption')->json();

        $this->assertSame(2, $json['summary']['total_line_items']);
        $this->assertEquals(5.0, $json['data'][0]['total_quantity']);
    }

    public function test_closed_work_order_remains_anchored_to_completed_at(): void
    {
        $part = $this->createPart('Filter');
        $asset = $this->createAsset();

        $oldCompletionRecentClosure = $this->createWorkOrder(
            WorkOrderStatus::CLOSED,
            $asset,
            now()->subDays(100),
            now()->subDay()
        );
        $this->addPart($oldCompletionRecentClosure, $part, 50);

        $recentCompletion = $this->createWorkOrder(
            WorkOrderStatus::CLOSED,
            $asset,
            now()->subDays(5),
            now()->subDay()
        );
        $this->addPart($recentCompletion, $part, 2);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/parts-consumption')->json();

        $this->assertSame(1, $json['summary']['total_line_items']);
        $this->assertEquals(2.0, $json['data'][0]['total_quantity']);
    }

    public function test_custom_date_window_includes_entire_to_date(): void
    {
        $part = $this->createPart('Filter');
        $asset = $this->createAsset();
        $completion = now()->subDays(10)->setTime(14, 0);
        $this->addPart($this->createWorkOrder(WorkOrderStatus::COMPLETED, $asset, $completion), $part, 2);

        $date = $completion->toDateString();
        $json = $this->actingAs($this->admin)
            ->getJson("/api/reports/parts-consumption?from={$date}&to={$date}")
            ->json();

        $this->assertSame(1, $json['summary']['total_line_items']);
    }

    public function test_part_filter_enables_quantity_summary_without_mixing_units(): void
    {
        $filter = $this->createPart('Filter', 'EA');
        $oil = $this->createPart('Oil', 'L');
        $asset = $this->createAsset();
        $workOrder = $this->createWorkOrder(WorkOrderStatus::COMPLETED, $asset, now()->subDay());
        $this->addPart($workOrder, $filter, 2);
        $this->addPart($workOrder, $oil, 10);

        $all = $this->actingAs($this->admin)->getJson('/api/reports/parts-consumption')->json();
        $this->assertNull($all['summary']['total_quantity']);
        $this->assertNull($all['summary']['unit_of_measure']);

        $filtered = $this->actingAs($this->admin)
            ->getJson('/api/reports/parts-consumption?part_id='.$filter->id)
            ->json();

        $this->assertEquals(2.0, $filtered['summary']['total_quantity']);
        $this->assertSame('EA', $filtered['summary']['unit_of_measure']);
        $this->assertCount(1, $filtered['data']);
        $this->assertSame($filter->id, $filtered['data'][0]['part_id']);
    }

    public function test_asset_and_fa_subclass_filters_apply_to_summary_and_rows(): void
    {
        $part = $this->createPart('Filter');
        $generator = $this->createAsset('GEN');
        $pump = $this->createAsset('PUMP');
        $this->addPart(
            $this->createWorkOrder(WorkOrderStatus::COMPLETED, $generator, now()->subDay()),
            $part,
            2
        );
        $this->addPart(
            $this->createWorkOrder(WorkOrderStatus::COMPLETED, $pump, now()->subDay()),
            $part,
            3
        );

        $byAsset = $this->actingAs($this->admin)
            ->getJson('/api/reports/parts-consumption?asset_id='.$generator->id)
            ->json();
        $this->assertSame(1, $byAsset['summary']['total_line_items']);
        $this->assertSame('GEN', $byAsset['data'][0]['fa_subclass_code']);

        $bySubclass = $this->actingAs($this->admin)
            ->getJson('/api/reports/parts-consumption?fa_subclass_code=PUMP')
            ->json();
        $this->assertSame(1, $bySubclass['summary']['total_line_items']);
        $this->assertEquals(3.0, $bySubclass['data'][0]['total_quantity']);
    }

    public function test_cursor_links_preserve_filters_and_traverse_grouped_rows(): void
    {
        $part = $this->createPart('Filter');
        foreach (['A', 'B', 'C', 'D', 'E'] as $subclass) {
            $asset = $this->createAsset($subclass);
            $workOrder = $this->createWorkOrder(WorkOrderStatus::COMPLETED, $asset, now()->subDay());
            $this->addPart($workOrder, $part, 1);
        }

        $seen = [];
        $url = '/api/reports/parts-consumption?part_id='.$part->id.'&per_page=2';
        do {
            $json = $this->actingAs($this->admin)->getJson($url)->json();
            foreach ($json['data'] as $item) {
                $seen[] = $item['fa_subclass_code'];
            }
            $url = $json['links']['next'] ?? null;
            if ($url !== null) {
                $this->assertStringContainsString('part_id='.$part->id, $url);
                $this->assertStringContainsString('per_page=2', $url);
            }
        } while ($url !== null);

        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $seen);
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/parts-consumption?from=2026-07-10&to=2026-07-01')
            ->assertUnprocessable();
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/parts-consumption')->json();

        $this->assertSame([
            'total_line_items' => 0,
            'distinct_parts' => 0,
            'distinct_work_orders' => 0,
            'total_quantity' => null,
            'unit_of_measure' => null,
        ], $json['summary']);
        $this->assertSame([], $json['data']);
    }
}
