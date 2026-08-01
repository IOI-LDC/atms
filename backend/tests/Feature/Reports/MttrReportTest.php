<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceCategory;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MttrReportTest extends TestCase
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

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/mttr')->assertUnauthorized();
    }

    public function test_calculates_mttr_by_technician(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        // Create corrective WO closed in last 90 days (assigned 48h before closed)
        $mr = MaintenanceRequest::forceCreate([
            'number' => 'MR-1',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Failure',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'created_at' => now()->subDays(10),
        ]);
        WorkOrder::forceCreate([
            'number' => 'WO-1',
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED,
            'priority' => 'high',
            'assigned_to_user_id' => $tech->id,
            'assigned_at' => now()->subDays(10),
            'closed_at' => now()->subDays(8),
            'closed_by_user_id' => $this->admin->id,
            'created_at' => now()->subDays(10),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/mttr?group_by=technician')->json();

        $this->assertSame(1, $json['summary']['repair_count']);
        $this->assertNotNull($json['summary']['mttr_hours']);
        // 48 hours between assigned_at and closed_at
        $this->assertEqualsWithDelta(48.0, $json['summary']['mttr_hours'], 0.1);
    }

    public function test_excludes_open_work_orders(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $mr = MaintenanceRequest::forceCreate([
            'number' => 'MR-1',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Failure',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'created_at' => now()->subDays(10),
        ]);
        WorkOrder::forceCreate([
            'number' => 'WO-1',
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::OPEN,
            'priority' => 'high',
            'assigned_to_user_id' => $tech->id,
            'assigned_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/mttr')->json();

        $this->assertSame(0, $json['summary']['repair_count']);
        $this->assertNull($json['summary']['mttr_hours']);
    }

    public function test_excludes_preventive_work_orders(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $mr = MaintenanceRequest::forceCreate([
            'number' => 'MR-1',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'PM',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'created_at' => now()->subDays(10),
        ]);
        WorkOrder::forceCreate([
            'number' => 'WO-1',
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED,
            'priority' => 'high',
            'assigned_to_user_id' => $tech->id,
            'assigned_at' => now()->subDays(10),
            'closed_at' => now()->subDays(8),
            'closed_by_user_id' => $this->admin->id,
            'created_at' => now()->subDays(10),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/mttr')->json();

        $this->assertSame(0, $json['summary']['repair_count']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/mttr')->json();

        $this->assertSame(0, $json['summary']['repair_count']);
        $this->assertNull($json['summary']['mttr_hours']);
        $this->assertSame([], $json['items']);
    }

    public function test_legacy_category_group_by_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/mttr?group_by=category')
            ->assertStatus(422);
    }

    public function test_groups_by_maintenance_category_code(): void
    {
        $category = MaintenanceCategory::factory()->create([
            'code' => 'MWD_LWD',
            'name' => 'MWD/LWD',
        ]);
        $asset = $this->createAsset(['maintenance_category_id' => $category->id]);
        $this->createClosedRepair($asset, 'MR-1', 'WO-1');

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/mttr?group_by=maintenance_category')->json();

        $this->assertCount(1, $json['items']);
        $this->assertSame('MWD_LWD', $json['items'][0]['group_key']);
        $this->assertSame('MWD/LWD', $json['items'][0]['group_label']);
    }

    /**
     * An asset with no explicit category is not category-less: it carries the
     * Unclassified default, which groups like any other category rather than
     * falling into the report's null bucket.
     */
    public function test_unclassified_assets_group_under_the_unclassified_category(): void
    {
        $asset = $this->createAsset();
        $this->createClosedRepair($asset, 'MR-1', 'WO-1');

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/mttr?group_by=maintenance_category')->json();

        $this->assertCount(1, $json['items']);
        $this->assertSame('UNCLASSIFIED', $json['items'][0]['group_key']);
        $this->assertSame('Unclassified', $json['items'][0]['group_label']);
    }

    public function test_groups_by_size_with_canonical_key_and_og_label(): void
    {
        $asset = $this->createAsset(['size_inches' => '6 3/4"']);
        $this->createClosedRepair($asset, 'MR-1', 'WO-1');

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/mttr?group_by=size')->json();

        $this->assertCount(1, $json['items']);
        $this->assertSame('6.75000', $json['items'][0]['group_key']);
        $this->assertSame('6 3/4"', $json['items'][0]['group_label']);
    }

    public function test_null_size_uses_unspecified_bucket(): void
    {
        $asset = $this->createAsset(['size_inches' => null]);
        $this->createClosedRepair($asset, 'MR-1', 'WO-1');

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/mttr?group_by=size')->json();

        $this->assertCount(1, $json['items']);
        $this->assertSame('unspecified', $json['items'][0]['group_key']);
        $this->assertSame('Unspecified', $json['items'][0]['group_label']);
    }

    /**
     * FA Subclass is ERP-owned, so it may not drive an ATMS report dimension.
     * Maintenance Category replaced it; this guards against reinstatement.
     */
    public function test_asset_class_group_by_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/mttr?group_by=asset_class')
            ->assertStatus(422);
    }

    public function test_maintenance_category_filter_applies(): void
    {
        $category = MaintenanceCategory::factory()->create(['code' => 'MWD', 'name' => 'MWD']);
        $inCategory = $this->createAsset(['maintenance_category_id' => $category->id]);
        $outOfCategory = $this->createAsset();
        $this->createClosedRepair($inCategory, 'MR-1', 'WO-1');
        $this->createClosedRepair($outOfCategory, 'MR-2', 'WO-2');

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/mttr?maintenance_category_id='.$category->id)->json();

        $this->assertCount(1, $json['items']);
    }

    private function createClosedRepair(Asset $asset, string $mrNumber, string $woNumber): void
    {
        $tech = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::TECHNICIAN->value)->firstOrFail()->id,
            'is_active' => true,
        ]);

        $mr = MaintenanceRequest::forceCreate([
            'number' => $mrNumber,
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Failure',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'created_at' => now()->subDays(10),
        ]);

        WorkOrder::forceCreate([
            'number' => $woNumber,
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED,
            'priority' => 'high',
            'assigned_to_user_id' => $tech->id,
            'assigned_at' => now()->subDays(10),
            'closed_at' => now()->subDays(8),
            'closed_by_user_id' => $this->admin->id,
            'created_at' => now()->subDays(10),
        ]);
    }
}
