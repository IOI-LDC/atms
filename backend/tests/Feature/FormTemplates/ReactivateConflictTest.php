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

class ReactivateConflictTest extends TestCase
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

    public function test_reactivation_blocked_when_another_active_template_shares_a_category(): void
    {
        $inactive = $this->template('Old', 'RC', false);
        $this->template('Current', 'RC', true);

        // Explicit conflict check in the action -> 409, not a raw DB 500.
        $this->actingAs($this->admin)->postJson("/api/admin/wo-forms/templates/{$inactive->id}/reactivate")
            ->assertStatus(409);

        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_reactivation_succeeds_when_the_category_is_free(): void
    {
        $template = $this->template('Solo', 'RF', false);

        $this->actingAs($this->admin)->postJson("/api/admin/wo-forms/templates/{$template->id}/reactivate")
            ->assertOk();

        $this->assertTrue($template->fresh()->is_active);
    }
}
