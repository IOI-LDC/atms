<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleCode;
use App\Models\MaintenanceCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $role)->first()->id,
            'is_active' => true,
        ]);
    }

    // ── index ───────────────────────────────────────────────────────────────

    public function test_admin_can_list_maintenance_categories_including_inactive(): void
    {
        MaintenanceCategory::factory()->create(['code' => 'MOTOR', 'name' => 'Motor']);
        MaintenanceCategory::factory()->create(['code' => 'JAR', 'name' => 'Jar']);
        MaintenanceCategory::factory()->inactive()->create(['code' => 'OLD', 'name' => 'Legacy']);

        $response = $this->actingAs($this->createUser(RoleCode::ADMINISTRATOR))
            ->getJson('/api/admin/maintenance-categories')
            ->assertOk();

        // All rows are returned (admin manages active + inactive), sorted by name.
        $this->assertSame(['Jar', 'Legacy', 'Motor'], collect($response->json('data'))->pluck('name')->all());
        $this->assertSame(
            [true, false, true],
            collect($response->json('data'))->pluck('is_active')->all(),
        );
    }

    // ── store ───────────────────────────────────────────────────────────────

    public function test_admin_can_create_maintenance_category_with_generated_code(): void
    {
        $response = $this->actingAs($this->createUser(RoleCode::ADMINISTRATOR))
            ->postJson('/api/admin/maintenance-categories', ['name' => 'Mud Motor'])
            ->assertCreated();

        $response->assertJsonPath('data.name', 'Mud Motor')
            ->assertJsonPath('data.code', 'MUD_MOTOR')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('maintenance_categories', [
            'code' => 'MUD_MOTOR',
            'name' => 'Mud Motor',
            'is_active' => true,
        ]);
    }

    public function test_generated_code_matches_import_transformation(): void
    {
        $this->actingAs($this->createUser(RoleCode::ADMINISTRATOR))
            ->postJson('/api/admin/maintenance-categories', ['name' => 'MWD/LWD'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'MWD_LWD');
    }

    public function test_create_rejects_duplicate_category_name(): void
    {
        MaintenanceCategory::factory()->create(['code' => 'MUD_MOTOR', 'name' => 'Mud Motor']);

        $this->actingAs($this->createUser(RoleCode::ADMINISTRATOR))
            ->postJson('/api/admin/maintenance-categories', ['name' => 'Mud Motor'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, MaintenanceCategory::count());
    }

    public function test_create_requires_name(): void
    {
        $this->actingAs($this->createUser(RoleCode::ADMINISTRATOR))
            ->postJson('/api/admin/maintenance-categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_create_rejects_names_that_cannot_produce_a_valid_code(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);

        foreach (['///', str_repeat('A', 51)] as $invalidName) {
            $this->actingAs($admin)
                ->postJson('/api/admin/maintenance-categories', ['name' => $invalidName])
                ->assertStatus(422)
                ->assertJsonValidationErrors('name');
        }

        $this->assertDatabaseCount('maintenance_categories', 0);
    }

    // ── update ──────────────────────────────────────────────────────────────

    public function test_admin_can_rename_maintenance_category_without_changing_code(): void
    {
        MaintenanceCategory::factory()->create(['code' => 'MOTOR', 'name' => 'Motor']);

        $response = $this->actingAs($this->createUser(RoleCode::ADMINISTRATOR))
            ->patchJson('/api/admin/maintenance-categories/MOTOR', ['name' => 'Mud Motor'])
            ->assertOk();

        $response->assertJsonPath('data.name', 'Mud Motor')
            ->assertJsonPath('data.code', 'MOTOR');
    }

    public function test_admin_can_deactivate_maintenance_category(): void
    {
        MaintenanceCategory::factory()->create(['code' => 'MOTOR', 'name' => 'Motor', 'is_active' => true]);

        $this->actingAs($this->createUser(RoleCode::ADMINISTRATOR))
            ->patchJson('/api/admin/maintenance-categories/MOTOR', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse(MaintenanceCategory::where('code', 'MOTOR')->first()->is_active);
    }

    // ── access control ──────────────────────────────────────────────────────

    public function test_non_admin_cannot_access_maintenance_category_crud(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        MaintenanceCategory::factory()->create(['code' => 'MOTOR', 'name' => 'Motor']);

        $this->actingAs($manager)->getJson('/api/admin/maintenance-categories')->assertForbidden();
        $this->actingAs($manager)->postJson('/api/admin/maintenance-categories', ['name' => 'X'])->assertForbidden();
        $this->actingAs($manager)->patchJson('/api/admin/maintenance-categories/MOTOR', ['name' => 'Y'])->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/maintenance-categories')->assertStatus(401);
    }
}
