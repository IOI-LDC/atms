<?php

namespace Tests\Feature\Parts;

use App\Enums\RoleCode;
use App\Models\MaintenanceCategory;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use App\Support\Size;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartMaintenanceCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        return $this->user(RoleCode::ADMINISTRATOR);
    }

    private function user(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    public function test_part_update_does_not_expose_a_legacy_category(): void
    {
        $part = Part::create([
            'erp_part_code' => 'P-100',
            'name' => 'Rotor',
        ]);

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/parts/{$part->id}", [
                'name' => 'Rotor Renamed',
                'category' => 'Attacker Supplied',
            ]);

        $response->assertOk();
        $part->refresh();

        $this->assertSame('Rotor Renamed', $part->name);
        $this->assertArrayNotHasKey('category', $response->json('data'));
    }

    public function test_part_update_sets_editable_fields(): void
    {
        $category = MaintenanceCategory::factory()->create([
            'code' => 'MUD_MOTOR',
            'name' => 'Mud Motor',
        ]);
        $part = Part::create([
            'erp_part_code' => 'P-101',
            'name' => 'Stator',
            'available_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/parts/{$part->id}", [
                'name' => 'Stator Assembly',
                'available_quantity' => 12.375,
                'maintenance_category_id' => $category->id,
                'size_inches' => '6 3/4',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Stator Assembly')
            ->assertJsonPath('data.available_quantity', 12.375)
            ->assertJsonPath('data.maintenance_category.id', $category->id)
            ->assertJsonPath('data.size', '6 3/4"')
            ->assertJsonPath('data.size_inches', '6.75000')
            ->assertJsonPath('data.is_active', false);

        $part->refresh();

        $this->assertSame('Stator Assembly', $part->name);
        $this->assertSame('12.375', $part->available_quantity);
        $this->assertSame($category->id, $part->maintenance_category_id);
        $this->assertSame('6.75000', $part->size_inches->canonical());
        $this->assertFalse($part->is_active);
    }

    public function test_part_update_can_clear_size_and_maintenance_category(): void
    {
        $category = MaintenanceCategory::factory()->create();
        $part = Part::create([
            'erp_part_code' => 'P-102',
            'name' => 'Bearing',
            'size_inches' => '6 3/4',
            'maintenance_category_id' => $category->id,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/parts/{$part->id}", [
                'size_inches' => null,
                'maintenance_category_id' => null,
            ])
            ->assertOk();

        $part->refresh();

        $this->assertNull($part->size_inches);
        $this->assertNull($part->maintenance_category_id);
    }

    public function test_part_update_rejects_invalid_editable_fields(): void
    {
        $part = Part::create(['erp_part_code' => 'P-109', 'name' => 'Seal']);

        $this->actingAs($this->admin())
            ->patchJson("/api/parts/{$part->id}", [
                'name' => '',
                'available_quantity' => -1,
                'maintenance_category_id' => 999999,
                'size_inches' => 'six inches',
                'is_active' => 'maybe',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'available_quantity',
                'maintenance_category_id',
                'size_inches',
                'is_active',
            ]);
    }

    public function test_part_update_rejects_quantity_above_database_precision(): void
    {
        $part = Part::create(['erp_part_code' => 'P-111', 'name' => 'Seal']);

        $this->actingAs($this->admin())
            ->patchJson("/api/parts/{$part->id}", [
                'available_quantity' => 100000000000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('available_quantity');
    }

    public function test_only_administrators_and_maintenance_managers_can_update_parts(): void
    {
        $part = Part::create(['erp_part_code' => 'P-110', 'name' => 'Seal']);

        $this->actingAs($this->user(RoleCode::TECHNICIAN))
            ->patchJson("/api/parts/{$part->id}", ['name' => 'Blocked'])
            ->assertForbidden();

        $this->actingAs($this->user(RoleCode::MAINTENANCE_MANAGER))
            ->patchJson("/api/parts/{$part->id}", ['name' => 'Manager Updated'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Manager Updated');
    }

    public function test_part_resource_exposes_size_part_number_and_category(): void
    {
        $category = MaintenanceCategory::factory()->create(['code' => 'MUD_MOTOR', 'name' => 'Mud Motor']);
        $part = Part::create([
            'erp_part_code' => 'P-103',
            'part_number' => 'A77-M6-22-SK',
            'name' => 'Adjustable Serv Kit',
            'size_inches' => '7 3/4',
            'maintenance_category_id' => $category->id,
        ]);

        $this->actingAs($this->admin())
            ->getJson("/api/parts/{$part->id}")
            ->assertOk()
            ->assertJsonPath('data.part_number', 'A77-M6-22-SK')
            ->assertJsonPath('data.size', '7 3/4"')
            ->assertJsonPath('data.size_inches', '7.75000')
            ->assertJsonPath('data.maintenance_category.code', 'MUD_MOTOR')
            ->assertJsonPath('data.maintenance_category.name', 'Mud Motor');
    }

    public function test_blank_size_and_category_are_null_in_the_resource(): void
    {
        $part = Part::create(['erp_part_code' => 'P-104', 'name' => 'Generic Seal']);

        $this->actingAs($this->admin())
            ->getJson("/api/parts/{$part->id}")
            ->assertOk()
            ->assertJsonPath('data.size', null)
            ->assertJsonPath('data.size_inches', null)
            ->assertJsonPath('data.part_number', null)
            ->assertJsonPath('data.maintenance_category', null);
    }

    public function test_size_round_trips_through_the_database_as_an_exact_value(): void
    {
        $part = Part::create([
            'erp_part_code' => 'P-105',
            'name' => 'Thirty-second',
            'size_inches' => '1 1/32',
        ]);

        $stored = $part->refresh()->size_inches;

        $this->assertInstanceOf(Size::class, $stored);
        $this->assertSame('1.03125', $stored->canonical());
        $this->assertSame('1 1/32"', $stored->format());
    }

    public function test_equivalent_size_spellings_store_identically(): void
    {
        $fraction = Part::create(['erp_part_code' => 'P-106', 'name' => 'A', 'size_inches' => '6 3/4']);
        $decimal = Part::create(['erp_part_code' => 'P-107', 'name' => 'B', 'size_inches' => '6.75"']);

        $this->assertSame(
            $fraction->refresh()->size_inches->canonical(),
            $decimal->refresh()->size_inches->canonical(),
        );
    }

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $category = MaintenanceCategory::factory()->create();
        Part::create([
            'erp_part_code' => 'P-108',
            'name' => 'Linked',
            'maintenance_category_id' => $category->id,
        ]);

        $this->expectException(QueryException::class);

        $category->delete();
    }
}
