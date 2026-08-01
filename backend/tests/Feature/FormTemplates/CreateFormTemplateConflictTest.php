<?php

namespace Tests\Feature\FormTemplates;

use App\Enums\RoleCode;
use App\Models\FormTemplate;
use App\Models\MaintenanceCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateFormTemplateConflictTest extends TestCase
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

    /**
     * Templates route by Maintenance Category now. Codes double as names here —
     * the tests only need a distinct, ATMS-owned category to point a form at.
     */
    private function category(string $code): MaintenanceCategory
    {
        return MaintenanceCategory::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true]);
    }

    private function template(string $name, string $categoryCode, bool $isActive): FormTemplate
    {
        $template = FormTemplate::create(['name' => $name, 'is_active' => $isActive]);
        $template->maintenanceCategories()->attach($this->category($categoryCode)->id, ['is_active' => $isActive]);

        return $template;
    }

    public function test_creating_a_second_active_template_for_a_category_returns_422(): void
    {
        $category = $this->category('CON');
        $this->template('First', 'CON', true);

        // The controller validation returns a clean 422 before the pivot's
        // partial unique index can raise a 500.
        $this->actingAs($this->admin)->postJson('/api/admin/wo-forms/templates', [
            'name' => 'Second',
            'maintenance_category_ids' => [$category->id],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('form_templates', ['name' => 'Second']);
    }
}
