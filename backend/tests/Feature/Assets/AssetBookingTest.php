<?php

namespace Tests\Feature\Assets;

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

class AssetBookingTest extends TestCase
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

    private function createAsset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'erp_asset_code' => 'AST-BOOK-001',
            'name' => 'Bookable Asset',
            'is_active' => true,
        ], $overrides));
    }

    private function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'booked_from' => now()->toDateString(),
            'booked_until' => now()->addDays(30)->toDateString(),
            'booking_reference' => 'JOB-2026-001',
            'notes' => 'Scheduled for well site deployment.',
        ], $overrides);
    }

    // ── Authorization ───────────────────────────────────────────────────────────

    public function test_administrator_can_create_booking(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.booking_reference', 'JOB-2026-001');
    }

    public function test_maintenance_manager_can_create_booking(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $this->actingAs($manager)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertCreated();
    }

    public function test_logistics_can_create_booking(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $asset = $this->createAsset();

        $this->actingAs($logistics)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertCreated();
    }

    public function test_technician_cannot_create_booking(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $this->actingAs($tech)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertForbidden();
    }

    public function test_requester_cannot_create_booking(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $this->actingAs($requester)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertForbidden();
    }

    // ── Validation ──────────────────────────────────────────────────────────────

    public function test_booked_from_is_required(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", ['booked_until' => '2026-09-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booked_from');
    }

    public function test_booked_until_must_be_after_or_equal_to_from(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", [
                'booked_from' => '2026-09-15',
                'booked_until' => '2026-09-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booked_until');
    }

    // ── Overlap detection ───────────────────────────────────────────────────────

    public function test_overlapping_booking_returns_409(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        // First booking: Aug 1 – Aug 31
        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload([
                'booked_from' => '2026-08-01',
                'booked_until' => '2026-08-31',
            ]))
            ->assertCreated();

        // Overlapping: Aug 15 – Sep 15
        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload([
                'booked_from' => '2026-08-15',
                'booked_until' => '2026-09-15',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Asset already has an active booking overlapping this date range.');
    }

    public function test_non_overlapping_booking_succeeds(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload([
                'booked_from' => '2026-08-01',
                'booked_until' => '2026-08-31',
            ]))
            ->assertCreated();

        // Non-overlapping: Sep 1 – Sep 30
        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload([
                'booked_from' => '2026-09-01',
                'booked_until' => '2026-09-30',
            ]))
            ->assertCreated();
    }

    public function test_cancelled_booking_does_not_block_new_booking(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload([
                'booked_from' => '2026-08-01',
                'booked_until' => '2026-08-31',
            ]));

        $bookingId = $response->json('data.id');

        // Cancel it
        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings/{$bookingId}/cancel")
            ->assertOk();

        // Same range should now succeed
        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload([
                'booked_from' => '2026-08-01',
                'booked_until' => '2026-08-31',
            ]))
            ->assertCreated();
    }

    // ── Inactive asset ──────────────────────────────────────────────────────────

    public function test_cannot_book_an_inactive_asset(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset(['is_active' => false]);

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot book an inactive asset.');
    }

    // ── Cancel ──────────────────────────────────────────────────────────────────

    public function test_cancel_active_booking(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload());

        $bookingId = $response->json('data.id');

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_already_cancelled_booking_returns_409(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $booking = Booking::create([
            'asset_id' => $asset->id,
            'booked_by' => $admin->id,
            'booked_from' => now()->toDateString(),
            'booked_until' => now()->addDays(10)->toDateString(),
            'status' => BookingStatus::CANCELLED,
            'cancelled_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings/{$booking->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only active bookings can be cancelled.');
    }

    public function test_technician_cannot_cancel_booking(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $booking = Booking::create([
            'asset_id' => $asset->id,
            'booked_by' => $admin->id,
            'booked_from' => now()->toDateString(),
            'booked_until' => now()->addDays(10)->toDateString(),
            'status' => BookingStatus::ACTIVE,
        ]);

        $this->actingAs($tech)
            ->postJson("/api/assets/{$asset->id}/bookings/{$booking->id}/cancel")
            ->assertForbidden();
    }

    // ── List bookings ───────────────────────────────────────────────────────────

    public function test_list_bookings_for_asset(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        Booking::create([
            'asset_id' => $asset->id,
            'booked_by' => $admin->id,
            'booked_from' => '2026-07-01',
            'booked_until' => '2026-07-31',
            'status' => BookingStatus::CANCELLED,
            'cancelled_at' => '2026-07-15',
        ]);

        Booking::create([
            'asset_id' => $asset->id,
            'booked_by' => $admin->id,
            'booked_from' => '2026-08-01',
            'booked_until' => '2026-08-31',
            'status' => BookingStatus::ACTIVE,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/assets/{$asset->id}/bookings");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ── Derived is_booked ───────────────────────────────────────────────────────

    public function test_is_booked_derived_in_asset_resource(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        // Not booked yet
        $this->actingAs($admin)
            ->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.is_booked', false);

        // Create a booking covering today
        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertCreated();

        $this->actingAs($admin)
            ->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.is_booked', true);
    }

    public function test_future_booking_does_not_make_asset_booked_today(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        // Booking starts tomorrow
        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload([
                'booked_from' => now()->addDay()->toDateString(),
                'booked_until' => now()->addDays(30)->toDateString(),
            ]))
            ->assertCreated();

        $this->actingAs($admin)
            ->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.is_booked', false);
    }

    // ── Auto-release on deactivation / withdrawal ───────────────────────────────

    public function test_inactivating_asset_releases_active_bookings(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertCreated();

        $this->actingAs($admin)
            ->patchJson("/api/assets/{$asset->id}", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('bookings', [
            'asset_id' => $asset->id,
            'status' => 'released',
        ]);
    }

    public function test_withdrawal_releases_active_bookings(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertCreated();

        $this->actingAs($admin)
            ->patchJson("/api/assets/{$asset->id}", ['maintenance_status' => 'withdrawn'])
            ->assertOk();

        $this->assertDatabaseHas('bookings', [
            'asset_id' => $asset->id,
            'status' => 'released',
        ]);
    }

    // ── Location change does NOT release ────────────────────────────────────────

    public function test_location_change_does_not_release_booking(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $locA = Location::create(['name' => 'Site A', 'type' => 'site', 'code' => 'SA', 'is_active' => true]);
        $locB = Location::create(['name' => 'Site B', 'type' => 'site', 'code' => 'SB', 'is_active' => true]);

        $asset = $this->createAsset(['current_location_id' => $locA->id]);

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/location", ['location_id' => $locB->id])
            ->assertOk();

        // Booking should still be active
        $this->assertDatabaseHas('bookings', [
            'asset_id' => $asset->id,
            'status' => 'active',
        ]);
    }

    // ── Asset list still shows is_booked ────────────────────────────────────────

    public function test_booked_asset_shows_in_asset_list(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset(['name' => 'Booked Visible Asset']);

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/bookings", $this->bookingPayload())
            ->assertCreated();

        $response = $this->actingAs($admin)->getJson('/api/assets?search=Booked Visible');

        $response->assertOk()
            ->assertJsonPath('data.0.is_booked', true);
    }

    // ── Unrelated: case-insensitive search ──────────────────────────────────────

    public function test_asset_search_is_case_insensitive(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset(['name' => 'Motor Assembly', 'erp_asset_code' => 'AST-MTR-01']);
        $this->createAsset(['name' => 'Conveyor Belt', 'erp_asset_code' => 'AST-CNV-01']);

        $byLower = $this->actingAs($admin)->getJson('/api/assets?search=motor');
        $byLower->assertOk();
        $this->assertContains('Motor Assembly', collect($byLower->json('data'))->pluck('name'));

        $byUpper = $this->actingAs($admin)->getJson('/api/assets?search=ASSEMBLY');
        $byUpper->assertOk();
        $this->assertContains('Motor Assembly', collect($byUpper->json('data'))->pluck('name'));

        $byCode = $this->actingAs($admin)->getJson('/api/assets?search=ast-mtr');
        $byCode->assertOk();
        $this->assertNotContains('Motor Assembly', collect($byCode->json('data'))->pluck('name'));
    }
}
