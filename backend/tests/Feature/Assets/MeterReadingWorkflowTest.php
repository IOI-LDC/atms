<?php

namespace Tests\Feature\Assets;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
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
}
