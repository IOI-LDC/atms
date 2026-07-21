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
