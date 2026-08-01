<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\MaintenanceCategory;
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

    /**
     * Assets group by Maintenance Category (ATMS-owned), never FA Subclass.
     * Categories are reused across calls so two assets named the same land in
     * the same group, which is what the aggregation tests rely on.
     */
    private function createAsset(string $categoryName = 'GEN', ?string $size = null): Asset
    {
        $category = MaintenanceCategory::firstOrCreate(
            ['code' => MaintenanceCategory::codeFor($categoryName)],
            ['name' => $categoryName, 'is_active' => true],
        );

        return Asset::create([
            'erp_asset_code' => 'ASSET-'.uniqid(),
            'name' => 'Asset',
            'maintenance_category_id' => $category->id,
            'size_inches' => $size,
            'is_active' => true,
        ]);
    }

    private function createPart(string $name, string $unit = 'EA', array $overrides = []): Part
    {
        return Part::create(array_merge([
            'erp_part_code' => 'PART-'.uniqid(),
            'name' => $name,
            'unit_of_measure' => $unit,
            'erp_status' => 'active',
            'is_active' => true,
        ], $overrides));
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

    private function findItem(array $items, int $partId, string $assetCategory, ?string $assetSizeInches = null): ?array
    {
        return collect($items)->first(
            fn (array $item): bool => $item['part_id'] === $partId
                && $item['asset_maintenance_category'] === $assetCategory
                && $item['asset_size_inches'] === $assetSizeInches
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

    public function test_aggregates_finalized_usage_by_part_and_asset_maintenance_category(): void
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
        $this->assertSame('EA', $generatorFilters['part']['unit_of_measure']);

        $pumpFilters = $this->findItem($json['data'], $filter->id, 'PUMP');
        $this->assertEquals(1.0, $pumpFilters['total_quantity']);
    }

    public function test_output_contains_part_identity_and_asset_dimensions(): void
    {
        $category = MaintenanceCategory::factory()->create([
            'code' => 'MUD_MOTOR',
            'name' => 'Mud Motor',
        ]);
        $part = $this->createPart('Rotor', 'EA', [
            'part_number' => 'PN-555',
            'size_inches' => '6 3/4"',
            'maintenance_category_id' => $category->id,
            'available_quantity' => 12,
        ]);
        $asset = $this->createAsset('MWD', '9 5/8"');
        $this->addPart(
            $this->createWorkOrder(WorkOrderStatus::COMPLETED, $asset, now()->subDay()),
            $part,
            3
        );

        $json = $this->actingAs($this->admin)->getJson('/api/reports/parts-consumption')->json();
        $row = $json['data'][0];

        // Nested Part Identity, same shape as PartIdentityResource.
        $this->assertSame($part->id, $row['part']['id']);
        $this->assertSame('Rotor', $row['part']['name']);
        $this->assertSame('PN-555', $row['part']['part_number']);
        $this->assertSame('EA', $row['part']['unit_of_measure']);
        $this->assertSame('6 3/4"', $row['part']['size']);
        $this->assertSame('6.75000', $row['part']['size_inches']);
        $this->assertSame('MUD_MOTOR', $row['part']['maintenance_category']['code']);
        $this->assertSame('Mud Motor', $row['part']['maintenance_category']['name']);
        $this->assertEquals(12.0, $row['part']['available_quantity']);

        // Asset dimensions.
        $this->assertSame('MWD', $row['asset_maintenance_category']);
        $this->assertSame('9 5/8"', $row['asset_size']);
        $this->assertSame('9.62500', $row['asset_size_inches']);

        // ERP part code is removed from the contract.
        $this->assertArrayNotHasKey('part_code', $row);
        $this->assertArrayNotHasKey('erp_part_code', $row);
        $this->assertArrayNotHasKey('erp_part_code', $row['part']);
        // FA Subclass is ERP-owned and must not appear in a report contract.
        $this->assertArrayNotHasKey('fa_subclass_code', $row);
        $this->assertArrayNotHasKey('asset_class', $row);
    }

    public function test_same_part_class_rows_with_different_asset_sizes_remain_separate(): void
    {
        $part = $this->createPart('Filter');
        $small = $this->createAsset('GEN', '6 3/4"');
        $large = $this->createAsset('GEN', '9 5/8"');

        $this->addPart($this->createWorkOrder(WorkOrderStatus::COMPLETED, $small, now()->subDay()), $part, 2);
        $this->addPart($this->createWorkOrder(WorkOrderStatus::COMPLETED, $large, now()->subDay()), $part, 5);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/parts-consumption')->json();

        $this->assertCount(2, $json['data']);
        $smallRow = $this->findItem($json['data'], $part->id, 'GEN', '6.75000');
        $largeRow = $this->findItem($json['data'], $part->id, 'GEN', '9.62500');
        $this->assertEquals(2.0, $smallRow['total_quantity']);
        $this->assertEquals(5.0, $largeRow['total_quantity']);
    }

    public function test_null_asset_size_appears_as_unspecified(): void
    {
        $part = $this->createPart('Filter');
        $asset = $this->createAsset('GEN', null);
        $this->addPart(
            $this->createWorkOrder(WorkOrderStatus::COMPLETED, $asset, now()->subDay()),
            $part,
            1
        );

        $json = $this->actingAs($this->admin)->getJson('/api/reports/parts-consumption')->json();

        $row = $json['data'][0];
        $this->assertSame('Unspecified', $row['asset_size']);
        $this->assertNull($row['asset_size_inches']);
    }

    public function test_cursor_pagination_covers_all_groups_without_duplicates_or_gaps(): void
    {
        $part = $this->createPart('Filter');

        // Same part across two asset classes, three sizes (incl. null).
        foreach (['ALPHA', 'BRAVO'] as $class) {
            foreach (['6 3/4"', '9 5/8"', null] as $size) {
                $asset = $this->createAsset($class, $size);
                $this->addPart(
                    $this->createWorkOrder(WorkOrderStatus::COMPLETED, $asset, now()->subDay()),
                    $part,
                    1
                );
            }
        }

        // A second part to verify part-level ordering across page boundaries.
        $second = $this->createPart('Bearing');
        $asset = $this->createAsset('ALPHA', '6 3/4"');
        $this->addPart(
            $this->createWorkOrder(WorkOrderStatus::COMPLETED, $asset, now()->subDay()),
            $second,
            2
        );

        // Walk all pages (per_page=2 → 4 pages for 7 groups).
        $seen = [];
        $url = '/api/reports/parts-consumption?per_page=2';
        do {
            $json = $this->actingAs($this->admin)->getJson($url)->json();
            foreach ($json['data'] as $item) {
                $seen[] = $item['part_id'].'|'.$item['asset_maintenance_category'].'|'.($item['asset_size_inches'] ?? 'null');
            }
            $url = $json['links']['next'] ?? null;
        } while ($url !== null);

        // 7 unique groups, no duplicates, no gaps.
        $this->assertCount(7, $seen);
        $this->assertCount(7, array_unique($seen));

        // Stable ordering across repeated requests.
        $again = [];
        $url = '/api/reports/parts-consumption?per_page=2';
        do {
            $json = $this->actingAs($this->admin)->getJson($url)->json();
            foreach ($json['data'] as $item) {
                $again[] = $item['part_id'].'|'.$item['asset_maintenance_category'].'|'.($item['asset_size_inches'] ?? 'null');
            }
            $url = $json['links']['next'] ?? null;
        } while ($url !== null);

        $this->assertSame($seen, $again);

        // Null sizes sort consistently after non-null sizes within a class.
        $filterKeys = array_values(array_filter($seen, fn ($k) => str_starts_with($k, $part->id.'|ALPHA')));
        $this->assertSame([
            $part->id.'|ALPHA|6.75000',
            $part->id.'|ALPHA|9.62500',
            $part->id.'|ALPHA|null',
        ], $filterKeys);
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

    public function test_asset_and_maintenance_category_filters_apply_to_summary_and_rows(): void
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
        $this->assertSame('GEN', $byAsset['data'][0]['asset_maintenance_category']);

        $byCategory = $this->actingAs($this->admin)
            ->getJson('/api/reports/parts-consumption?maintenance_category_id='.$pump->maintenance_category_id)
            ->json();
        $this->assertSame(1, $byCategory['summary']['total_line_items']);
        $this->assertEquals(3.0, $byCategory['data'][0]['total_quantity']);
    }

    public function test_cursor_links_preserve_filters_and_traverse_grouped_rows(): void
    {
        $part = $this->createPart('Filter');
        foreach (['A', 'B', 'C', 'D', 'E'] as $categoryName) {
            $asset = $this->createAsset($categoryName);
            $workOrder = $this->createWorkOrder(WorkOrderStatus::COMPLETED, $asset, now()->subDay());
            $this->addPart($workOrder, $part, 1);
        }

        $seen = [];
        $url = '/api/reports/parts-consumption?part_id='.$part->id.'&per_page=2';
        do {
            $json = $this->actingAs($this->admin)->getJson($url)->json();
            foreach ($json['data'] as $item) {
                $seen[] = $item['asset_maintenance_category'];
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
