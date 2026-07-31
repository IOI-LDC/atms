<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingStatus;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingReportTest extends TestCase
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

    private function bookAsset(Asset $asset): void
    {
        Booking::create([
            'asset_id' => $asset->id,
            'booked_by' => $this->admin->id,
            'booked_from' => now()->subDay()->toDateString(),
            'booked_until' => now()->addDays(30)->toDateString(),
            'status' => BookingStatus::ACTIVE,
        ]);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/booking')->assertUnauthorized();
    }

    public function test_counts_booked_vs_available(): void
    {
        $location = Location::create(['name' => 'Workshop', 'type' => 'building']);

        $booked1 = $this->createAsset(['name' => 'Booked-1', 'current_location_id' => $location->id]);
        $booked2 = $this->createAsset(['name' => 'Booked-2', 'current_location_id' => $location->id]);
        $this->createAsset(['name' => 'Available', 'current_location_id' => $location->id]);

        $this->bookAsset($booked1);
        $this->bookAsset($booked2);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/booking')->json();

        $this->assertSame(3, $json['summary']['total_assets']);
        $this->assertSame(2, $json['summary']['booked_count']);
        $this->assertSame(1, $json['summary']['available_count']);
        $this->assertCount(1, $json['items']);
        $this->assertSame(2, $json['items'][0]['booked_count']);
        $this->assertSame(1, $json['items'][0]['available_count']);
    }

    public function test_groups_by_location(): void
    {
        $locA = Location::create(['name' => 'Loc-A', 'type' => 'building']);
        $locB = Location::create(['name' => 'Loc-B', 'type' => 'building']);

        $assetA1 = $this->createAsset(['current_location_id' => $locA->id]);
        $this->createAsset(['current_location_id' => $locA->id]);
        $assetB1 = $this->createAsset(['current_location_id' => $locB->id]);

        $this->bookAsset($assetA1);
        $this->bookAsset($assetB1);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/booking')->json();

        $this->assertSame(3, $json['summary']['total_assets']);
        $this->assertSame(2, $json['summary']['booked_count']);
        $this->assertCount(2, $json['items']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/booking')->json();

        $this->assertSame(0, $json['summary']['total_assets']);
        $this->assertSame(0, $json['summary']['booked_count']);
        $this->assertSame(0, $json['summary']['available_count']);
        $this->assertSame([], $json['items']);
    }
}
