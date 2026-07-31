<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/reports/asset-status-distribution')->assertUnauthorized();
    }

    public function test_every_authenticated_role_can_view_reports(): void
    {
        foreach ([
            RoleCode::ADMINISTRATOR,
            RoleCode::MAINTENANCE_MANAGER,
            RoleCode::TECHNICIAN,
            RoleCode::REQUESTER,
            RoleCode::LOGISTICS,
        ] as $roleCode) {
            $this->actingAs($this->createUser($roleCode))
                ->getJson('/api/reports/asset-status-distribution')
                ->assertOk();
        }
    }
}
