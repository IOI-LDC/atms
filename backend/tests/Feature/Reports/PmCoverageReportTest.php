<?php

namespace Tests\Feature\Reports;

use App\Enums\AssetKind;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetPmAssignment;
use App\Models\Location;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PmCoverageReportTest extends TestCase
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

    private function createPmRule(): PmRule
    {
        return PmRule::create([
            'name' => 'Rule-'.uniqid(),
            'trigger_type' => 'date',
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/pm-coverage')->assertUnauthorized();
    }

    public function test_identifies_uncovered_assets(): void
    {
        $covered = $this->createAsset(['name' => 'Covered']);
        $uncovered = $this->createAsset(['name' => 'Uncovered']);

        $rule = $this->createPmRule();
        AssetPmAssignment::create([
            'asset_id' => $covered->id,
            'pm_rule_id' => $rule->id,
            'is_active' => true,
            'assigned_by' => $this->admin->id,
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-coverage')->json();

        $this->assertSame(2, $json['summary']['total_assets']);
        $this->assertSame(1, $json['summary']['covered_assets']);
        $this->assertSame(1, $json['summary']['uncovered_assets']);
        $this->assertEquals(50.0, $json['summary']['coverage_pct']);
        $this->assertCount(1, $json['data']);
        $this->assertSame('Uncovered', $json['data'][0]['name']);
    }

    public function test_excludes_inactive_assignments(): void
    {
        $asset = $this->createAsset();
        $rule = $this->createPmRule();
        AssetPmAssignment::create([
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'is_active' => false,
            'assigned_by' => $this->admin->id,
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-coverage')->json();

        $this->assertSame(1, $json['summary']['uncovered_assets']);
    }

    public function test_excludes_inactive_assets(): void
    {
        $this->createAsset(['is_active' => false]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-coverage')->json();

        $this->assertSame(0, $json['summary']['total_assets']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-coverage')->json();

        $this->assertSame(0, $json['summary']['total_assets']);
        $this->assertSame(0, $json['summary']['covered_assets']);
        $this->assertSame(0, $json['summary']['uncovered_assets']);
        $this->assertNull($json['summary']['coverage_pct']);
        $this->assertSame([], $json['data']);
    }
}
