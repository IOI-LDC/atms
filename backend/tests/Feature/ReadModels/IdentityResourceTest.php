<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\MaintenanceCategory;
use App\Models\MaintenanceRequest;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared Asset and Part identity shapes.
 *
 * Every embedded reference carries the same fields so one frontend component
 * can render name + value-only badges everywhere, and no embed leaks an ERP
 * identifier.
 */
class IdentityResourceTest extends TestCase
{
    use RefreshDatabase;

    private MaintenanceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->category = MaintenanceCategory::factory()->create(['code' => 'MOTOR', 'name' => 'Motor']);
    }

    private function user(RoleCode $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $role)->first()->id,
            'is_active' => true,
        ]);
    }

    private function asset(): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'FA000001',
            'asset_tag' => 'L-MTR-958-0011',
            'name' => '9 5/8" Motor Assembly',
            'serial_number' => 'M7-962-0011',
            'size_inches' => '9 5/8',
            'maintenance_category_id' => $this->category->id,
            'is_active' => true,
        ]);
    }

    private function part(): Part
    {
        return Part::create([
            'erp_part_code' => 'P-001',
            'part_number' => 'A77-M6-22-SK',
            'name' => 'Adjustable Serv Kit',
            'size_inches' => '9 5/8',
            'maintenance_category_id' => $this->category->id,
            'available_quantity' => 4,
            'is_active' => true,
        ]);
    }

    private function workOrderFor(Asset $asset): WorkOrder
    {
        $mr = MaintenanceRequest::create([
            'number' => 'MR-000001',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'medium',
            'description' => 'Test',
            'created_by' => $this->user(RoleCode::ADMINISTRATOR)->id,
            'is_preventive' => false,
        ]);

        return WorkOrder::create([
            'number' => 'WO-000001',
            'maintenance_request_id' => $mr->id,
            'asset_id' => $asset->id,
            'status' => 'open',
            'priority' => 'medium',
            'description' => 'Test',
        ]);
    }

    public function test_maintenance_request_embeds_the_full_asset_identity(): void
    {
        $asset = $this->asset();
        $this->workOrderFor($asset);

        $embed = $this->actingAs($this->user(RoleCode::TECHNICIAN))
            ->getJson('/api/maintenance-requests')
            ->assertOk()
            ->json('data.0.asset');

        $this->assertSame('9 5/8" Motor Assembly', $embed['name']);
        $this->assertSame('L-MTR-958-0011', $embed['asset_tag']);
        $this->assertSame('M7-962-0011', $embed['serial_number']);
        $this->assertSame('9 5/8"', $embed['size']);
        $this->assertSame('Motor', $embed['maintenance_category']['name']);
        $this->assertArrayNotHasKey('erp_asset_code', $embed);
    }

    public function test_work_order_embeds_the_identity_plus_operational_status(): void
    {
        $asset = $this->asset();
        $this->workOrderFor($asset);

        // Admin, not Technician: WO list scoping shows a Technician only their
        // own assignments, and this work order is unassigned.
        $embed = $this->actingAs($this->user(RoleCode::ADMINISTRATOR))
            ->getJson('/api/work-orders')
            ->assertOk()
            ->json('data.0.asset');

        $this->assertSame('9 5/8"', $embed['size']);
        $this->assertSame('Motor', $embed['maintenance_category']['name']);
        $this->assertArrayHasKey('operational_status', $embed);
        $this->assertArrayNotHasKey('erp_asset_code', $embed);
    }

    public function test_work_order_part_line_embeds_the_full_part_identity(): void
    {
        $asset = $this->asset();
        $workOrder = $this->workOrderFor($asset);
        $part = $this->part();

        $admin = $this->user(RoleCode::ADMINISTRATOR);
        $this->actingAs($admin)
            ->postJson("/api/work-orders/{$workOrder->id}/parts", ['part_id' => $part->id, 'quantity' => 2])
            ->assertCreated();

        $embed = $this->actingAs($admin)
            ->getJson("/api/work-orders/{$workOrder->id}")
            ->assertOk()
            ->json('data.parts.0.part');

        $this->assertSame('Adjustable Serv Kit', $embed['name']);
        $this->assertSame('A77-M6-22-SK', $embed['part_number']);
        $this->assertSame('9 5/8"', $embed['size']);
        $this->assertSame('Motor', $embed['maintenance_category']['name']);
        // JSON renders 4.0 as 4, so compare loosely on the numeric value.
        $this->assertEquals(4.0, $embed['available_quantity']);

        // RQ4: erp_part_code now travels with every part identity, so the
        // printable Part Request gets it from the shared shape rather than a
        // local merge in WorkOrderPartResource.
        $this->assertSame('P-001', $embed['erp_part_code']);
    }

    public function test_the_shared_part_identity_carries_the_erp_code_for_every_role(): void
    {
        $part = $this->part();

        $embed = $this->actingAs($this->user(RoleCode::TECHNICIAN))
            ->getJson('/api/parts')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Adjustable Serv Kit', $embed['name']);
        // Was Admin-only and deliberately hidden. RQ4 reversed that: it is the
        // "No." column LDC works from, so a technician picking a part sees it.
        $this->assertSame('P-001', $embed['erp_part_code']);
    }

    public function test_missing_values_are_null_rather_than_absent(): void
    {
        Asset::create([
            'erp_asset_code' => 'FA000002',
            'name' => 'Bare Asset',
            'is_active' => true,
        ]);

        $embed = $this->actingAs($this->user(RoleCode::ADMINISTRATOR))
            ->getJson('/api/assets')
            ->assertOk()
            ->json('data');

        $bare = collect($embed)->firstWhere('name', 'Bare Asset');

        $this->assertNull($bare['serial_number']);
        $this->assertNull($bare['size']);
        // The category is never absent: an unclassified asset carries the
        // Unclassified default rather than a null.
        $this->assertSame('Unclassified', $bare['maintenance_category']['name']);
    }

    public function test_erp_codes_remain_visible_to_administrators_on_detail_payloads(): void
    {
        $asset = $this->asset();
        $part = $this->part();
        $admin = $this->user(RoleCode::ADMINISTRATOR);

        $this->actingAs($admin)->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.erp_asset_code', 'FA000001');

        $this->actingAs($admin)->getJson("/api/parts/{$part->id}")
            ->assertOk()
            ->assertJsonPath('data.erp_part_code', 'P-001');
    }

    public function test_asset_class_is_exposed_separately_from_maintenance_category(): void
    {
        $asset = $this->asset();
        $asset->update(['fa_subclass_code' => 'MUD MOTOR']);

        $this->actingAs($this->user(RoleCode::ADMINISTRATOR))
            ->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.asset_class', 'MUD MOTOR')
            ->assertJsonPath('data.maintenance_category.name', 'Motor');
    }
}
