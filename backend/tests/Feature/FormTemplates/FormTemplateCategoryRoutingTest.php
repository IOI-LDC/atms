<?php

namespace Tests\Feature\FormTemplates;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\FormTemplate;
use App\Models\MaintenanceCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-011: WO Form templates are routed by Maintenance Category.
 *
 * A form serves one or more categories, an asset carries exactly one, and at
 * most one *active* template may claim any category — that last rule is what
 * keeps `FormTemplate::activeForCategory()` a single deterministic answer, and
 * it is enforced in the pivot rather than trusted to callers.
 */
class FormTemplateCategoryRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function category(string $code): MaintenanceCategory
    {
        return MaintenanceCategory::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true]);
    }

    public function test_a_form_can_serve_several_categories(): void
    {
        $motors = $this->category('MOTORS');
        $jars = $this->category('JARS');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/wo-forms/templates', [
                'name' => 'Downhole inspection',
                'maintenance_category_ids' => [$motors->id, $jars->id],
            ])
            ->assertCreated();

        $this->assertEqualsCanonicalizing(
            ['JARS', 'MOTORS'],
            collect($response->json('data.maintenance_categories'))->pluck('code')->all(),
        );

        $template = FormTemplate::first();
        $this->assertSame($template->id, FormTemplate::activeForCategory($motors->id)?->id);
        $this->assertSame($template->id, FormTemplate::activeForCategory($jars->id)?->id);
    }

    public function test_a_category_served_by_no_active_form_resolves_to_nothing(): void
    {
        $this->assertNull(FormTemplate::activeForCategory($this->category('ORPHAN')->id));
    }

    public function test_a_second_active_form_cannot_claim_a_taken_category(): void
    {
        $shared = $this->category('SHARED');
        $free = $this->category('FREE');

        $this->actingAs($this->admin)->postJson('/api/admin/wo-forms/templates', [
            'name' => 'First',
            'maintenance_category_ids' => [$shared->id],
        ])->assertCreated();

        // Rejected even though one of the two categories is free — the clash on
        // the other is enough, because resolution must stay unambiguous.
        $this->actingAs($this->admin)->postJson('/api/admin/wo-forms/templates', [
            'name' => 'Second',
            'maintenance_category_ids' => [$free->id, $shared->id],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('form_templates', ['name' => 'Second']);
    }

    public function test_deactivating_a_form_releases_its_categories(): void
    {
        $category = $this->category('RELEASE');

        $first = FormTemplate::create(['name' => 'First', 'is_active' => true]);
        $first->maintenanceCategories()->attach($category->id, ['is_active' => true]);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/wo-forms/templates/{$first->id}/deactivate")
            ->assertOk();

        $this->actingAs($this->admin)->postJson('/api/admin/wo-forms/templates', [
            'name' => 'Successor',
            'maintenance_category_ids' => [$category->id],
        ])->assertCreated();

        $this->assertSame('Successor', FormTemplate::activeForCategory($category->id)?->name);
    }

    public function test_reactivation_names_the_category_and_the_form_holding_it(): void
    {
        $category = $this->category('CLASH');

        $retired = FormTemplate::create(['name' => 'Retired', 'is_active' => false]);
        $retired->maintenanceCategories()->attach($category->id, ['is_active' => false]);

        $current = FormTemplate::create(['name' => 'Current', 'is_active' => true]);
        $current->maintenanceCategories()->attach($category->id, ['is_active' => true]);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/wo-forms/templates/{$retired->id}/reactivate")
            ->assertStatus(409)
            ->assertJsonPath('message', '"Current" is already the active form for CLASH.');

        $this->assertFalse($retired->fresh()->is_active);
    }

    public function test_a_form_with_no_categories_cannot_be_activated(): void
    {
        // The state every pre-D-011 template migrated into: fields intact,
        // inactive, nothing assigned.
        $orphan = FormTemplate::create(['name' => 'Migrated', 'is_active' => false]);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/wo-forms/templates/{$orphan->id}/reactivate")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Assign at least one maintenance category before activating this form.');
    }

    public function test_coverage_can_be_edited_after_creation(): void
    {
        $before = $this->category('BEFORE');
        $after = $this->category('AFTER');

        $template = FormTemplate::create(['name' => 'Movable', 'is_active' => true]);
        $template->maintenanceCategories()->attach($before->id, ['is_active' => true]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/wo-forms/templates/{$template->id}", [
                'maintenance_category_ids' => [$after->id],
            ])
            ->assertOk();

        $this->assertNull(FormTemplate::activeForCategory($before->id));
        $this->assertSame($template->id, FormTemplate::activeForCategory($after->id)?->id);
    }

    public function test_editing_coverage_onto_a_taken_category_is_refused(): void
    {
        $taken = $this->category('TAKEN');

        $holder = FormTemplate::create(['name' => 'Holder', 'is_active' => true]);
        $holder->maintenanceCategories()->attach($taken->id, ['is_active' => true]);

        $mover = FormTemplate::create(['name' => 'Mover', 'is_active' => true]);
        $mover->maintenanceCategories()->attach($this->category('MINE')->id, ['is_active' => true]);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/wo-forms/templates/{$mover->id}", [
                'maintenance_category_ids' => [$taken->id],
            ])
            ->assertStatus(409);

        $this->assertSame($holder->id, FormTemplate::activeForCategory($taken->id)?->id);
    }

    public function test_templates_can_be_filtered_by_category(): void
    {
        $wanted = $this->category('WANTED');

        $match = FormTemplate::create(['name' => 'Match', 'is_active' => true]);
        $match->maintenanceCategories()->attach($wanted->id, ['is_active' => true]);

        $other = FormTemplate::create(['name' => 'Other', 'is_active' => true]);
        $other->maintenanceCategories()->attach($this->category('OTHER')->id, ['is_active' => true]);

        $names = $this->actingAs($this->admin)
            ->getJson('/api/admin/wo-forms/templates?maintenance_category_id='.$wanted->id)
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Match'], $names);
    }

    /**
     * An asset's category is what selects its form, so an unclassified asset
     * gets whatever form serves Unclassified — usually none.
     */
    public function test_resolution_follows_the_assets_category(): void
    {
        $category = $this->category('RESOLVE');
        $template = FormTemplate::create(['name' => 'Resolver', 'is_active' => true]);
        $template->maintenanceCategories()->attach($category->id, ['is_active' => true]);

        $classified = Asset::create([
            'erp_asset_code' => 'AST-'.uniqid(),
            'name' => 'Classified',
            'is_active' => true,
            'maintenance_category_id' => $category->id,
        ]);
        $unclassified = Asset::create([
            'erp_asset_code' => 'AST-'.uniqid(),
            'name' => 'Unclassified',
            'is_active' => true,
        ]);

        $this->assertSame($template->id, FormTemplate::activeForCategory($classified->maintenance_category_id)?->id);
        $this->assertNull(FormTemplate::activeForCategory($unclassified->maintenance_category_id));
    }

    /**
     * The FA subclass CRUD existed only to feed the dropdown this work replaced.
     * The read-only list-options route is a different controller and survives.
     */
    public function test_the_fa_subclass_admin_crud_is_gone(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/fa-subclass-type-codes')->assertNotFound();
        $this->actingAs($this->admin)->postJson('/api/admin/fa-subclass-type-codes', [
            'fa_subclass_code' => 'NEW', 'type_code' => 'NEW',
        ])->assertNotFound();
        $this->actingAs($this->admin)->patchJson('/api/admin/fa-subclass-type-codes/MWD', [])->assertNotFound();
        $this->actingAs($this->admin)->deleteJson('/api/admin/fa-subclass-type-codes/MWD')->assertNotFound();

        $this->actingAs($this->admin)->getJson('/api/list-options/fa_subclass_type_codes')->assertOk();
    }
}
