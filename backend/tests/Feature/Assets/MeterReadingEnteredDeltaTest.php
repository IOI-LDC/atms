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

/**
 * The work order form takes a delta and posts the computed absolute total. This
 * keeps what the operator actually typed, so a wrong total can be traced to
 * either a mistyped delta or a bad base.
 *
 * Informational only: nothing in PM evaluation, the monotonicity guards, or the
 * reports reads `entered_delta` — `reading_value` stays authoritative.
 */
class MeterReadingEnteredDeltaTest extends TestCase
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

    public function test_the_entered_delta_is_stored_alongside_the_absolute_total(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-DL-1', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $response = $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/meter-readings", [
            'usage_reading_type_id' => $type->id,
            'reading_value' => 1248,   // base 1240 + delta 8
            'entered_delta' => 8,
            'reading_at' => now()->toIso8601String(),
            'source' => 'manual',
        ])->assertCreated();

        $reading = AssetMeterReading::find($response->json('data.id'));
        $this->assertEquals(1248.0, (float) $reading->reading_value);
        $this->assertEquals(8.0, (float) $reading->entered_delta);
    }

    public function test_the_delta_is_optional(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-DL-2', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $response = $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/meter-readings", [
            'usage_reading_type_id' => $type->id,
            'reading_value' => 500,
            'reading_at' => now()->toIso8601String(),
            'source' => 'manual',
        ])->assertCreated();

        $this->assertNull(AssetMeterReading::find($response->json('data.id'))->entered_delta);
    }

    public function test_the_resource_exposes_the_delta(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-DL-3', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 1248,
            'entered_delta' => 8,
            'reading_at' => now(),
            'source' => 'manual',
        ]);

        $this->actingAs($tech)->getJson("/api/assets/{$asset->id}/meter-readings")
            ->assertOk()
            ->assertJsonPath('data.0.entered_delta', '8.00');
    }

    /**
     * The edit dialog takes an absolute value, so a delta recorded at entry stops
     * describing the reading the moment the total changes.
     */
    public function test_editing_the_value_clears_the_stale_delta(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-DL-4', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $reading = AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 1248,
            'entered_delta' => 8,
            'reading_at' => now(),
            'source' => 'manual',
        ]);

        $this->actingAs($tech)->patchJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}", [
            'reading_value' => 1300,
            'reading_at' => now()->toIso8601String(),
        ])->assertOk();

        $this->assertNull($reading->fresh()->entered_delta);
    }

    /**
     * A technician at the machine often knows only what the meter has moved since
     * the last reading, not its lifetime total — so a reading entered as a delta
     * has to be correctable as that same delta, with the absolute recomputed.
     */
    public function test_an_edit_can_supply_a_new_delta_and_both_figures_stay_in_step(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-DL-6', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        // Previous reading in the series — the base the delta measures from.
        AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 255,
            'reading_at' => '2026-07-30 08:00:00',
            'source' => 'manual',
        ]);

        $reading = AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 280,   // 255 + 25
            'entered_delta' => 25,
            'reading_at' => '2026-08-05 08:00:00',
            'source' => 'manual',
        ]);

        // The operator corrects 25 to 40; the caller resolves 255 + 40 = 295.
        $this->actingAs($tech)->patchJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}", [
            'reading_value' => 295,
            'entered_delta' => 40,
            'reading_at' => '2026-08-05 08:00:00',
        ])->assertOk();

        $reading = $reading->fresh();
        $this->assertEquals(40.0, (float) $reading->entered_delta);
        $this->assertEquals(295.0, (float) $reading->reading_value);
    }

    public function test_editing_only_the_notes_keeps_the_delta(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-DL-5', 'name' => 'Gen']);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);

        $reading = AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 1248,
            'entered_delta' => 8,
            'reading_at' => '2026-08-01 08:00:00',
            'source' => 'manual',
        ]);

        $this->actingAs($tech)->patchJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}", [
            'reading_value' => 1248,
            'reading_at' => '2026-08-01 08:00:00',
            'notes' => 'Corrected the note only',
        ])->assertOk();

        $this->assertEquals(8.0, (float) $reading->fresh()->entered_delta);
    }
}
