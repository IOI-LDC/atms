<?php

namespace Tests\Feature\Locations;

use App\Enums\RoleCode;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListActiveLocationsTest extends TestCase
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

    public function test_authorized_roles_can_list_active_locations_only(): void
    {
        Location::create(['name' => 'Active Workshop', 'type' => 'workshop', 'code' => 'AW', 'is_active' => true]);
        Location::create(['name' => 'Inactive Yard', 'type' => 'yard', 'code' => 'IY', 'is_active' => false]);

        foreach ([RoleCode::ADMINISTRATOR, RoleCode::MAINTENANCE_MANAGER, RoleCode::LOGISTICS] as $role) {
            $this->actingAs($this->createUser($role))
                ->getJson('/api/locations')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonFragment(['name' => 'Active Workshop', 'code' => 'AW', 'is_active' => true])
                ->assertJsonMissing(['name' => 'Inactive Yard']);
        }
    }

    public function test_unauthorized_roles_are_forbidden(): void
    {
        Location::create(['name' => 'Site', 'type' => 'site', 'is_active' => true]);

        foreach ([RoleCode::TECHNICIAN, RoleCode::REQUESTER] as $role) {
            $this->actingAs($this->createUser($role))
                ->getJson('/api/locations')
                ->assertForbidden();
        }
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/locations')->assertUnauthorized();
    }

    public function test_active_locations_sorted_by_name(): void
    {
        Location::create(['name' => 'Zeta', 'type' => 'site', 'is_active' => true]);
        Location::create(['name' => 'Alpha', 'type' => 'site', 'is_active' => true]);

        $response = $this->actingAs($this->createUser(RoleCode::LOGISTICS))->getJson('/api/locations');

        $response->assertOk();
        $this->assertSame(['Alpha', 'Zeta'], array_column($response->json('data'), 'name'));
    }

    public function test_response_shape_matches_spec(): void
    {
        Location::create([
            'name' => 'Workshop',
            'type' => 'workshop',
            'code' => 'WS',
            'description' => 'Main workshop facility',
            'is_active' => true,
        ]);

        $this->actingAs($this->createUser(RoleCode::LOGISTICS))
            ->getJson('/api/locations')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'type', 'code', 'description', 'is_active']],
            ]);
    }
}
