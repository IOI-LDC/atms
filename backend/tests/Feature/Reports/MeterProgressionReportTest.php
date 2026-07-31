<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeterProgressionReportTest extends TestCase
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

    private function createAsset(string $name = 'Asset'): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'ASSET-'.uniqid(),
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function createReadingType(string $name = 'Hours', string $unit = 'h'): UsageReadingType
    {
        return UsageReadingType::create(['name' => $name, 'unit' => $unit, 'is_active' => true]);
    }

    private function createReading(
        Asset $asset,
        UsageReadingType $type,
        float $value,
        \DateTimeInterface $readingAt,
        bool $confirmed = true,
    ): AssetMeterReading {
        return AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => $value,
            'reading_at' => $readingAt,
            'source' => 'manual',
            'entered_by_user_id' => $this->admin->id,
            'confirmed_by_user_id' => $confirmed ? $this->admin->id : null,
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/reports/meter-progression')->assertUnauthorized();
    }

    public function test_returns_confirmed_progression_with_deltas(): void
    {
        $asset = $this->createAsset('Pump');
        $type = $this->createReadingType();
        $this->createReading($asset, $type, 100, now()->subDays(20));
        $this->createReading($asset, $type, 150, now()->subDays(10));
        $this->createReading($asset, $type, 200, now()->subDays(5), false);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/meter-progression')->json();

        $this->assertSame(2, $json['summary']['total_readings']);
        $this->assertSame(2, $json['summary']['confirmed_readings']);
        $this->assertCount(2, $json['data']);
        $this->assertEquals(150.0, $json['data'][0]['reading_value']);
        $this->assertEquals(100.0, $json['data'][0]['previous_reading_value']);
        $this->assertEquals(50.0, $json['data'][0]['delta']);
        $this->assertNull($json['data'][1]['previous_reading_value']);
        $this->assertNull($json['data'][1]['delta']);
    }

    public function test_delta_uses_previous_confirmed_reading_outside_window(): void
    {
        $asset = $this->createAsset();
        $type = $this->createReadingType();
        $this->createReading($asset, $type, 100, now()->subDays(100));
        $this->createReading($asset, $type, 140, now()->subDays(5));

        $json = $this->actingAs($this->admin)->getJson('/api/reports/meter-progression')->json();

        $this->assertSame(1, $json['summary']['total_readings']);
        $this->assertCount(1, $json['data']);
        $this->assertEquals(40.0, $json['data'][0]['delta']);
    }

    public function test_asset_and_reading_type_filters_apply(): void
    {
        $assetA = $this->createAsset('A');
        $assetB = $this->createAsset('B');
        $hours = $this->createReadingType('Hours', 'h');
        $cycles = $this->createReadingType('Cycles', 'cycles');
        $this->createReading($assetA, $hours, 10, now()->subDay());
        $this->createReading($assetA, $cycles, 20, now()->subDay());
        $this->createReading($assetB, $hours, 30, now()->subDay());

        $json = $this->actingAs($this->admin)->getJson(
            '/api/reports/meter-progression?asset_id='.$assetA->id.'&usage_reading_type_id='.$hours->id
        )->json();

        $this->assertSame(1, $json['summary']['total_readings']);
        $this->assertSame($assetA->id, $json['data'][0]['asset']['id']);
        $this->assertSame($hours->id, $json['data'][0]['reading_type']['id']);
    }

    public function test_date_filter_includes_entire_to_date(): void
    {
        $asset = $this->createAsset();
        $type = $this->createReadingType();
        $readingAt = now()->subDays(5)->setTime(14, 0);
        $this->createReading($asset, $type, 10, $readingAt);
        $date = $readingAt->toDateString();

        $json = $this->actingAs($this->admin)
            ->getJson("/api/reports/meter-progression?from={$date}&to={$date}")
            ->json();

        $this->assertSame(1, $json['summary']['total_readings']);
    }

    public function test_cursor_links_preserve_filters_and_traverse_duplicate_timestamps(): void
    {
        $asset = $this->createAsset();
        $type = $this->createReadingType();
        $readingAt = now()->subDay();
        foreach (range(1, 5) as $value) {
            $this->createReading($asset, $type, $value, $readingAt);
        }

        $seen = [];
        $url = '/api/reports/meter-progression?asset_id='.$asset->id.'&per_page=2';
        do {
            $json = $this->actingAs($this->admin)->getJson($url)->json();
            foreach ($json['data'] as $item) {
                $seen[] = $item['id'];
            }
            $url = $json['links']['next'] ?? null;
            if ($url !== null) {
                $this->assertStringContainsString('asset_id='.$asset->id, $url);
                $this->assertStringContainsString('per_page=2', $url);
            }
        } while ($url !== null);

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/meter-progression?from=2026-07-10&to=2026-07-01')
            ->assertUnprocessable();
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/meter-progression')->json();

        $this->assertSame(['total_readings' => 0, 'confirmed_readings' => 0], $json['summary']);
        $this->assertSame([], $json['data']);
    }
}
