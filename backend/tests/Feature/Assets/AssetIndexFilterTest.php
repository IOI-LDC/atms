<?php

namespace Tests\Feature\Assets;

use App\Enums\MaintenanceStatus;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function asset(MaintenanceStatus $status, string $name): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-FILTER-'.uniqid(),
            'name' => $name,
            'maintenance_status' => $status,
            'is_active' => true,
        ]);
    }

    /**
     * The asset pickers search server-side, so every identifier they display
     * has to be matchable — including on a fragment from the middle of it.
     */
    private function searchableAsset(): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-SEARCH-'.uniqid(),
            'name' => '12 1/8" SPIRAL SCREW STABILIZER',
            'serial_number' => 'WZL-160-04',
            // asset_tag is varchar(15); one per test is enough to stay unique.
            'asset_tag' => 'L-MTR-218-6004',
            'maintenance_status' => MaintenanceStatus::ENROLLED,
            'is_active' => true,
        ]);
    }

    public function test_search_matches_a_serial_number_fragment(): void
    {
        $match = $this->searchableAsset();
        $other = $this->asset(MaintenanceStatus::ENROLLED, 'Unrelated Asset');

        $this->actingAs($this->admin())
            ->getJson('/api/assets?search=L-16')
            ->assertOk()
            ->assertJsonFragment(['id' => $match->id])
            ->assertJsonMissing(['id' => $other->id]);
    }

    public function test_search_matches_a_serial_number_case_insensitively(): void
    {
        $match = $this->searchableAsset();

        $this->actingAs($this->admin())
            ->getJson('/api/assets?search=wzl-160-04')
            ->assertOk()
            ->assertJsonFragment(['id' => $match->id]);
    }

    public function test_search_matches_an_asset_tag_fragment(): void
    {
        $match = $this->searchableAsset();
        $other = $this->asset(MaintenanceStatus::ENROLLED, 'Unrelated Asset');

        $this->actingAs($this->admin())
            ->getJson('/api/assets?search=MTR-218')
            ->assertOk()
            ->assertJsonFragment(['id' => $match->id])
            ->assertJsonMissing(['id' => $other->id]);
    }

    public function test_search_still_matches_name_and_erp_code(): void
    {
        $match = $this->searchableAsset();

        $this->actingAs($this->admin())
            ->getJson('/api/assets?search=SPIRAL')
            ->assertOk()
            ->assertJsonFragment(['id' => $match->id]);

        // The ERP asset code is deliberately NOT searchable.
        $this->actingAs($this->admin())
            ->getJson('/api/assets?search='.$match->erp_asset_code)
            ->assertOk()
            ->assertJsonMissing(['id' => $match->id]);
    }

    /** A null serial number must not blow up or match everything. */
    public function test_search_excludes_assets_with_a_null_serial_number(): void
    {
        $match = $this->searchableAsset();
        $nullSerial = $this->asset(MaintenanceStatus::ENROLLED, 'No Serial Asset');

        $this->actingAs($this->admin())
            ->getJson('/api/assets?search=WZL')
            ->assertOk()
            ->assertJsonFragment(['id' => $match->id])
            ->assertJsonMissing(['id' => $nullSerial->id]);
    }

    public function test_index_maintenance_status_enrolled_excludes_withdrawn(): void
    {
        $enrolled = $this->asset(MaintenanceStatus::ENROLLED, 'Enrolled Asset');
        $withdrawn = $this->asset(MaintenanceStatus::WITHDRAWN, 'Withdrawn Asset');

        $this->actingAs($this->admin())
            ->getJson('/api/assets?maintenance_status=enrolled')
            ->assertOk()
            ->assertJsonFragment(['id' => $enrolled->id])
            ->assertJsonMissing(['id' => $withdrawn->id]);
    }

    public function test_index_maintenance_status_withdrawn_excludes_enrolled(): void
    {
        $enrolled = $this->asset(MaintenanceStatus::ENROLLED, 'Enrolled Asset');
        $withdrawn = $this->asset(MaintenanceStatus::WITHDRAWN, 'Withdrawn Asset');

        $this->actingAs($this->admin())
            ->getJson('/api/assets?maintenance_status=withdrawn')
            ->assertOk()
            ->assertJsonFragment(['id' => $withdrawn->id])
            ->assertJsonMissing(['id' => $enrolled->id]);
    }

    public function test_index_without_maintenance_status_returns_both(): void
    {
        $enrolled = $this->asset(MaintenanceStatus::ENROLLED, 'Enrolled Asset');
        $withdrawn = $this->asset(MaintenanceStatus::WITHDRAWN, 'Withdrawn Asset');

        $this->actingAs($this->admin())
            ->getJson('/api/assets')
            ->assertOk()
            ->assertJsonFragment(['id' => $enrolled->id])
            ->assertJsonFragment(['id' => $withdrawn->id]);
    }
}
