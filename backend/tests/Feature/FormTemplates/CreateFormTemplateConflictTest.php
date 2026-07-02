<?php

namespace Tests\Feature\FormTemplates;

use App\Enums\RoleCode;
use App\Models\FaSubclassTypeCode;
use App\Models\FormTemplate;
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

    public function test_creating_a_second_active_template_for_a_subclass_returns_422(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'CON', 'type_code' => 'ABC']);
        FormTemplate::create(['name' => 'First', 'fa_subclass_code' => 'CON', 'is_active' => true]);

        // The controller validation returns a clean 422 before the partial
        // unique index can raise a 500.
        $this->actingAs($this->admin)->postJson('/api/admin/wo-forms/templates', [
            'name' => 'Second',
            'fa_subclass_code' => 'CON',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('form_templates', ['name' => 'Second']);
    }
}
