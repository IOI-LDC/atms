<?php

namespace Tests\Feature\Assets;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeterReadingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    public function test_requester_creates_unverified_reading_but_cannot_confirm(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = Asset::create(['erp_asset_code' => 'AST-RD-1', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $payload = [
            'usage_reading_type_id' => $type->id,
            'reading_value' => 100.5,
            'reading_at' => now()->toIso8601String(),
            'source' => 'user',
        ];

        // Can record
        $response = $this->actingAs($requester)->postJson("/api/assets/{$asset->id}/meter-readings", $payload);
        $response->assertCreated();
        $readingId = $response->json('data.id');

        // Cannot confirm
        $this->actingAs($requester)->postJson("/api/assets/{$asset->id}/meter-readings/{$readingId}/confirm")
            ->assertForbidden();
    }

    public function test_technician_can_confirm_reading(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-RD-2', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);
        $reading = AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 100,
            'reading_at' => now(),
            'source' => 'system',
        ]);

        $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}/confirm")
            ->assertOk();

        $this->assertNotNull($reading->fresh()->confirmed_at);
        $this->assertEquals($tech->id, $reading->fresh()->confirmed_by_user_id);
    }

    public function test_confirmed_readings_cannot_decrease(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-RD-3', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        // Existing confirmed reading
        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 500,
            'reading_at' => now()->subDay(),
            'source' => 'user',
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $tech->id,
        ]);

        // New unverified reading, lower value
        $lowerReading = AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 450, // Lower!
            'reading_at' => now(),
            'source' => 'user',
        ]);

        // Attempt to confirm
        $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/meter-readings/{$lowerReading->id}/confirm")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Confirmed readings must not be lower than the latest confirmed reading.');

        $this->assertNull($lowerReading->fresh()->confirmed_at);
    }

    public function test_confirmed_readings_cannot_have_earlier_date(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-RD-4', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        // Existing confirmed reading
        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 500,
            'reading_at' => now()->subDays(2),
            'source' => 'user',
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $tech->id,
        ]);

        // New unverified reading, valid value, but EARLIER date
        $earlierReading = AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 510,
            'reading_at' => now()->subDays(3), // Earlier!
            'source' => 'user',
        ]);

        $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/meter-readings/{$earlierReading->id}/confirm")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Reading date cannot be earlier than the latest confirmed reading date.');

        $this->assertNull($earlierReading->fresh()->confirmed_at);
    }

    /**
     * A reading taken from a work order is attributed to it, so the work order
     * page can tell its own readings apart from the asset's prior history.
     */
    public function test_a_reading_can_be_attributed_to_a_work_order(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->workOrderFor($asset = $this->assetWithWorkOrder());
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $response = $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/meter-readings", [
            'usage_reading_type_id' => $type->id,
            'reading_value' => 1240,
            'reading_at' => now()->toIso8601String(),
            'source' => 'manual',
            'work_order_id' => $wo->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('asset_meter_readings', [
            'id' => $response->json('data.id'),
            'asset_id' => $asset->id,
            'work_order_id' => $wo->id,
        ]);

        $this->actingAs($tech)->getJson("/api/assets/{$asset->id}/meter-readings")
            ->assertOk()
            ->assertJsonPath('data.0.work_order_id', $wo->id);
    }

    /** Readings recorded outside a work order stay unattributed — that is history. */
    public function test_a_reading_without_a_work_order_is_unattributed(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-RD-6', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/meter-readings", [
            'usage_reading_type_id' => $type->id,
            'reading_value' => 90,
            'reading_at' => now()->toIso8601String(),
            'source' => 'manual',
        ])->assertCreated();

        $this->actingAs($tech)->getJson("/api/assets/{$asset->id}/meter-readings")
            ->assertOk()
            ->assertJsonPath('data.0.work_order_id', null);
    }

    /** A reading cannot be filed against a work order for some other asset. */
    public function test_a_work_order_on_a_different_asset_is_rejected(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->workOrderFor($this->assetWithWorkOrder());
        $otherAsset = Asset::create(['erp_asset_code' => 'AST-RD-7', 'name' => 'Other']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $this->actingAs($tech)->postJson("/api/assets/{$otherAsset->id}/meter-readings", [
            'usage_reading_type_id' => $type->id,
            'reading_value' => 10,
            'reading_at' => now()->toIso8601String(),
            'source' => 'manual',
            'work_order_id' => $wo->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That work order belongs to a different asset.');
    }

    private function assetWithWorkOrder(): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-RD-WO-'.uniqid(),
            'name' => 'Metered',
            'is_active' => true,
        ]);
    }

    private function workOrderFor(Asset $asset): WorkOrder
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'description' => 'Reading check',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", [
            'is_failure' => true,
        ])->assertOk();

        return WorkOrder::where('maintenance_request_id', $mr->id)->first();
    }
}
