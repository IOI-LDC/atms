<?php

namespace Tests\Feature\Jobs;

use App\Actions\Pm\EvaluatePmRule;
use App\Jobs\EvaluatePmRulesJob;
use App\Models\Asset;
use App\Models\Location;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluatePmRulesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $role = Role::first();
        User::factory()->create([
            'email' => 'system@atms.internal',
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    public function test_job_evaluates_active_rules(): void
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-001', 'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
        $admin = User::first();
        PmRule::create([
            'asset_id' => $asset->id, 'name' => 'Rule 1', 'trigger_type' => 'date',
            'interval_days' => 30, 'is_active' => true, 'created_by' => $admin->id,
        ]);

        $this->mock(EvaluatePmRule::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->andReturn(null);
        });

        (new EvaluatePmRulesJob)->handle(app(EvaluatePmRule::class));
    }

    public function test_job_skips_inactive_rules(): void
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-002', 'erp_asset_code' => 'A-002', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
        $admin = User::first();
        PmRule::create([
            'asset_id' => $asset->id, 'name' => 'Rule 2', 'trigger_type' => 'date',
            'interval_days' => 30, 'is_active' => false, 'created_by' => $admin->id,
        ]);

        $this->mock(EvaluatePmRule::class, function ($mock) {
            $mock->shouldNotReceive('execute');
        });

        (new EvaluatePmRulesJob)->handle(app(EvaluatePmRule::class));
    }

    public function test_job_throws_when_system_user_missing(): void
    {
        User::where('email', 'system@atms.internal')->delete();

        $this->expectException(\RuntimeException::class);

        (new EvaluatePmRulesJob)->handle(app(EvaluatePmRule::class));
    }
}
