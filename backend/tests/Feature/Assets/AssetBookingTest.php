<?php

namespace Tests\Feature\Assets;

use App\Enums\RoleCode;
use App\Models\Asset;
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

    // ── Authorization ───────────────────────────────────────────────────────────

    public function test_administrator_can_book_and_unbook(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/book")
            ->assertOk()
            ->assertJsonPath('data.is_booked', true);

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/unbook")
            ->assertOk()
            ->assertJsonPath('data.is_booked', false);
    }

    public function test_maintenance_manager_can_book_and_unbook(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $this->actingAs($manager)->postJson("/api/assets/{$asset->id}/book")->assertOk();
        $this->actingAs($manager)->postJson("/api/assets/{$asset->id}/unbook")->assertOk();
    }

    public function test_logistics_can_book_and_unbook(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $asset = $this->createAsset();

        $this->actingAs($logistics)->postJson("/api/assets/{$asset->id}/book")->assertOk();
        $this->actingAs($logistics)->postJson("/api/assets/{$asset->id}/unbook")->assertOk();
    }

    public function test_technician_cannot_book_or_unbook(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/book")->assertForbidden();
        $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/unbook")->assertForbidden();
    }

    public function test_requester_cannot_book_or_unbook(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $this->actingAs($requester)->postJson("/api/assets/{$asset->id}/book")->assertForbidden();
        $this->actingAs($requester)->postJson("/api/assets/{$asset->id}/unbook")->assertForbidden();
    }

    // ── Behaviour ───────────────────────────────────────────────────────────────

    public function test_booking_persists_to_database(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/book")->assertOk();

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'is_booked' => true]);
    }

    public function test_booking_an_already_booked_asset_returns_409(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset(['is_booked' => true]);

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/book")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Asset is already booked.');
    }

    public function test_unbooking_an_unbooked_asset_returns_409(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset(['is_booked' => false]);

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/unbook")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Asset is not booked.');
    }

    public function test_cannot_book_an_inactive_asset(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset(['is_active' => false]);

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/book")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot book an inactive asset.');
    }

    // ── Auto-release on location change ─────────────────────────────────────────

    public function test_location_change_auto_releases_booking(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        $locA = Location::create(['name' => 'Site A', 'type' => 'site', 'code' => 'SA', 'is_active' => true]);
        $locB = Location::create(['name' => 'Site B', 'type' => 'site', 'code' => 'SB', 'is_active' => true]);

        $asset = $this->createAsset([
            'current_location_id' => $locA->id,
            'is_booked' => true,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/location", ['location_id' => $locB->id])
            ->assertOk();

        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'is_booked' => false,
            'current_location_id' => $locB->id,
        ]);
    }

    public function test_same_location_does_not_release_booking(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $locA = Location::create(['name' => 'Site A', 'type' => 'site', 'code' => 'SA', 'is_active' => true]);

        $asset = $this->createAsset([
            'current_location_id' => $locA->id,
            'is_booked' => true,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/assets/{$asset->id}/location", ['location_id' => $locA->id])
            ->assertOk();

        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'is_booked' => true,
        ]);
    }

    // ── Auto-clear on inactivation ──────────────────────────────────────────────

    public function test_inactivating_asset_clears_booking(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset(['is_booked' => true]);

        $this->actingAs($admin)
            ->patchJson("/api/assets/{$asset->id}", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'is_booked' => false,
            'is_active' => false,
        ]);
    }

    public function test_setting_maintenance_status_withdrawn_clears_booking(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset(['is_booked' => true]);

        $this->actingAs($admin)
            ->patchJson("/api/assets/{$asset->id}", ['maintenance_status' => 'withdrawn'])
            ->assertOk();

        $this->assertDatabaseHas('assets', [
            'id' => $asset->id,
            'is_booked' => false,
            'maintenance_status' => 'withdrawn',
        ]);
    }

    // ── Maintenance non-interference ────────────────────────────────────────────

    public function test_booked_asset_still_shows_in_asset_list(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createAsset(['is_booked' => true, 'name' => 'Booked Visible Asset']);

        $response = $this->actingAs($admin)->getJson('/api/assets?search=Booked Visible');

        $response->assertOk()
            ->assertJsonPath('data.0.is_booked', true);
    }

    public function test_asset_search_is_case_insensitive(): void
    {
        // Regression for case-sensitive search on PostgreSQL: plain LIKE is
        // case-sensitive there (and case-insensitive on SQLite, which masked
        // the bug). Reproduces the reported "motor" vs "Motor" symptom.
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
        $this->assertContains('Motor Assembly', collect($byCode->json('data'))->pluck('name'));
    }
}
