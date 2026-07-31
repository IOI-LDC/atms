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

class FormTemplatePolicyTest extends TestCase
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

    private function createUser(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createSubclass(string $code): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => $code, 'type_code' => 'ABC']);
    }

    public function test_admin_can_list_templates(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/wo-forms/templates')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_admin_can_create_template(): void
    {
        $this->createSubclass('POL');

        $this->actingAs($this->admin)->postJson('/api/admin/wo-forms/templates', [
            'name' => 'Policy template',
            'fa_subclass_code' => 'POL',
        ])->assertCreated();
    }

    public function test_manager_is_denied_even_on_view(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);

        $this->actingAs($manager)->getJson('/api/admin/wo-forms/templates')->assertForbidden();

        $this->actingAs($manager)->postJson('/api/admin/wo-forms/templates', [
            'name' => 'x',
            'fa_subclass_code' => 'x',
        ])->assertForbidden();
    }

    public function test_technician_is_denied_on_template_management(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);

        $this->actingAs($tech)->getJson('/api/admin/wo-forms/templates')->assertForbidden();
    }

    public function test_logistics_is_denied(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);

        $this->actingAs($logistics)->getJson('/api/admin/wo-forms/templates')->assertForbidden();
    }

    public function test_requester_is_denied(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);

        $this->actingAs($requester)->getJson('/api/admin/wo-forms/templates')->assertForbidden();
    }

    public function test_field_management_is_admin_only(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $this->createSubclass('FLD');
        $template = FormTemplate::create(['name' => 'T', 'fa_subclass_code' => 'FLD', 'is_active' => true]);

        $this->actingAs($manager)->postJson("/api/admin/wo-forms/templates/{$template->id}/fields", [
            'label' => 'Field',
            'field_type' => 'text',
        ])->assertForbidden();
    }
}
