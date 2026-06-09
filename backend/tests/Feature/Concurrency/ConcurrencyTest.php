<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Assets\ConfirmMeterReading;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\Location;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_meter_confirmation_is_idempotent(): void
    {
        $techRole = Role::where('code', RoleCode::TECHNICIAN)->first();
        $tech = User::factory()->create([
            'role_id' => $techRole->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $managerRole = Role::where('code', RoleCode::MAINTENANCE_MANAGER)->first();
        $manager = User::factory()->create([
            'role_id' => $managerRole->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-001', 'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);

        $readingType = UsageReadingType::create([
            'name' => 'Hours',
            'unit' => 'h',
            'is_active' => true,
        ]);

        $reading = AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 100,
            'reading_at' => now()->subDay(),
            'source' => 'user',
            'entered_by_user_id' => $tech->id,
        ]);

        $action = app(ConfirmMeterReading::class);

        $first = $action->execute($reading, $manager->id);
        $this->assertNotNull($first->confirmed_at);
        $this->assertEquals($manager->id, $first->confirmed_by_user_id);
        $firstConfirmedAt = $first->confirmed_at;

        $second = $action->execute($reading->fresh(), $manager->id);
        $this->assertNotNull($second->confirmed_at);
        $this->assertEquals($firstConfirmedAt, $second->confirmed_at);
        $this->assertEquals($manager->id, $second->confirmed_by_user_id);
    }
}
