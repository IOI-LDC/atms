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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeterReadingEditDeleteTest extends TestCase
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

    private function createReading(Asset $asset, UsageReadingType $type, array $overrides = []): AssetMeterReading
    {
        return AssetMeterReading::create(array_merge([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 100,
            'reading_at' => now(),
            'source' => 'user',
        ], $overrides));
    }

    public static function allowedRoles(): array
    {
        return [
            'administrator' => [RoleCode::ADMINISTRATOR],
            'maintenance_manager' => [RoleCode::MAINTENANCE_MANAGER],
            'technician' => [RoleCode::TECHNICIAN],
        ];
    }

    public static function deniedRoles(): array
    {
        return [
            'logistics' => [RoleCode::LOGISTICS],
            'requester' => [RoleCode::REQUESTER],
            'service' => [RoleCode::SERVICE],
        ];
    }

    #[DataProvider('allowedRoles')]
    public function test_allowed_roles_can_update_unconfirmed_reading(RoleCode $role): void
    {
        $user = $this->createUser($role);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-1', 'name' => 'Gen', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($asset, $type);

        $response = $this->actingAs($user)->patchJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}", [
            'reading_value' => 250.75,
            'reading_at' => now()->addDay()->toIso8601String(),
            'notes' => 'Corrected value',
        ]);

        $response->assertOk()->assertJsonPath('data.id', $reading->id);

        $reading->refresh();
        $this->assertSame('250.75', (string) $reading->reading_value);
        $this->assertSame('Corrected value', $reading->notes);
    }

    #[DataProvider('allowedRoles')]
    public function test_allowed_roles_can_delete_unconfirmed_reading(RoleCode $role): void
    {
        $user = $this->createUser($role);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-2', 'name' => 'Gen', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($asset, $type);

        $response = $this->actingAs($user)->deleteJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}");

        $response->assertOk()->assertJsonPath('message', 'Meter reading deleted.');

        // Soft-deleted: the row remains but is excluded by the SoftDeletes scope.
        $this->assertSoftDeleted('asset_meter_readings', ['id' => $reading->id]);
        $this->assertNull(AssetMeterReading::find($reading->id));
    }

    #[DataProvider('deniedRoles')]
    public function test_denied_roles_cannot_update_reading(RoleCode $role): void
    {
        $user = $this->createUser($role);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-3', 'name' => 'Gen', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($asset, $type);

        $this->actingAs($user)->patchJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}", [
            'reading_value' => 999,
            'reading_at' => now()->toIso8601String(),
        ])->assertForbidden();
    }

    #[DataProvider('deniedRoles')]
    public function test_denied_roles_cannot_delete_reading(RoleCode $role): void
    {
        $user = $this->createUser($role);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-4', 'name' => 'Gen', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($asset, $type);

        $this->actingAs($user)->deleteJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}")
            ->assertForbidden();
    }

    public function test_updating_a_confirmed_reading_returns_409(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-5', 'name' => 'Gen', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($asset, $type, [
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $tech->id,
        ]);

        $originalValue = $reading->reading_value;

        $this->actingAs($tech)->patchJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}", [
            'reading_value' => 999,
            'reading_at' => now()->toIso8601String(),
        ])->assertStatus(409);

        $this->assertSame($originalValue, $reading->refresh()->reading_value);
    }

    public function test_deleting_a_confirmed_reading_returns_409(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-6', 'name' => 'Gen', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($asset, $type, [
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $tech->id,
        ]);

        $this->actingAs($tech)->deleteJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}")
            ->assertStatus(409);

        $this->assertNotNull(AssetMeterReading::find($reading->id));
    }

    public function test_scope_mismatch_on_update_returns_404(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $assetA = Asset::create(['erp_asset_code' => 'AST-ED-7A', 'name' => 'A', 'is_active' => true]);
        $assetB = Asset::create(['erp_asset_code' => 'AST-ED-7B', 'name' => 'B', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($assetA, $type);

        $this->actingAs($tech)->patchJson("/api/assets/{$assetB->id}/meter-readings/{$reading->id}", [
            'reading_value' => 999,
            'reading_at' => now()->toIso8601String(),
        ])->assertNotFound();
    }

    public function test_scope_mismatch_on_delete_returns_404(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $assetA = Asset::create(['erp_asset_code' => 'AST-ED-8A', 'name' => 'A', 'is_active' => true]);
        $assetB = Asset::create(['erp_asset_code' => 'AST-ED-8B', 'name' => 'B', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($assetA, $type);

        $this->actingAs($tech)->deleteJson("/api/assets/{$assetB->id}/meter-readings/{$reading->id}")
            ->assertNotFound();
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_returns_422(array $payload): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-9', 'name' => 'Gen', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($asset, $type);

        $this->actingAs($tech)->patchJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}", $payload)
            ->assertStatus(422);
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'missing value' => [['reading_at' => now()->toIso8601String()]],
            'non-numeric value' => [['reading_value' => 'not-a-number', 'reading_at' => now()->toIso8601String()]],
            'missing date' => [['reading_value' => 100]],
            'invalid date' => [['reading_value' => 100, 'reading_at' => 'not-a-date']],
        ];
    }

    public function test_updating_inactive_asset_returns_422(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-10', 'name' => 'Gen', 'is_active' => false]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($asset, $type);

        $this->actingAs($tech)->patchJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}", [
            'reading_value' => 200,
            'reading_at' => now()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_updating_inactive_reading_type_returns_422(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-11', 'name' => 'Gen', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => false]);
        $reading = $this->createReading($asset, $type);

        $this->actingAs($tech)->patchJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}", [
            'reading_value' => 200,
            'reading_at' => now()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_update_writes_audit_entry(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-12', 'name' => 'Gen', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($asset, $type);

        $this->actingAs($tech)->patchJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}", [
            'reading_value' => 300,
            'reading_at' => now()->toIso8601String(),
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'meter_reading.updated',
            'subject_type' => $reading->getMorphClass(),
            'subject_id' => $reading->id,
            'user_id' => $tech->id,
        ]);
    }

    public function test_delete_writes_audit_entry(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = Asset::create(['erp_asset_code' => 'AST-ED-13', 'name' => 'Gen', 'is_active' => true]);
        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $reading = $this->createReading($asset, $type);

        $this->actingAs($tech)->deleteJson("/api/assets/{$asset->id}/meter-readings/{$reading->id}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'meter_reading.deleted',
            'subject_type' => $reading->getMorphClass(),
            'subject_id' => $reading->id,
            'user_id' => $tech->id,
        ]);
    }
}
