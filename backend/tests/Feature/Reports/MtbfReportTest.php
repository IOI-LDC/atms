<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MtbfReportTest extends TestCase
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

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/mtbf')->assertUnauthorized();
    }

    public function test_calculates_mtbf_by_asset(): void
    {
        $asset = $this->createAsset();
        // Create 2 corrective failures in the last 90 days
        MaintenanceRequest::forceCreate([
            'number' => 'MR-1',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Failure 1',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'is_failure' => true,
            'created_at' => now()->subDays(10),
        ]);
        MaintenanceRequest::forceCreate([
            'number' => 'MR-2',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Failure 2',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'is_failure' => true,
            'created_at' => now()->subDays(20),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/mtbf?group_by=asset')->json();

        $this->assertSame(2, $json['summary']['failure_count']);
        $this->assertNotNull($json['summary']['mtbf_days']);
        $this->assertCount(1, $json['items']);
        $this->assertSame(2, $json['items'][0]['failure_count']);
    }

    public function test_excludes_non_failures(): void
    {
        $asset = $this->createAsset();
        // Corrective but not classified as failure
        MaintenanceRequest::forceCreate([
            'number' => 'MR-1',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Not a failure',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'is_failure' => false,
            'created_at' => now()->subDays(10),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/mtbf')->json();

        $this->assertSame(0, $json['summary']['failure_count']);
        $this->assertNull($json['summary']['mtbf_days']);
    }

    public function test_respects_date_window(): void
    {
        $asset = $this->createAsset();
        // Failure outside 90-day window
        MaintenanceRequest::forceCreate([
            'number' => 'MR-1',
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'high',
            'description' => 'Old failure',
            'created_by' => $this->admin->id,
            'is_preventive' => false,
            'is_failure' => true,
            'created_at' => now()->subDays(100),
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/mtbf')->json();

        $this->assertSame(0, $json['summary']['failure_count']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/mtbf')->json();

        $this->assertSame(0, $json['summary']['failure_count']);
        $this->assertNull($json['summary']['mtbf_days']);
        $this->assertSame([], $json['items']);
    }
}
