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

    public function test_reactivation_blocked_when_another_active_template_shares_subclass(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'RC', 'type_code' => 'ABC']);

        $inactive = FormTemplate::create(['name' => 'Old', 'fa_subclass_code' => 'RC', 'is_active' => false]);
        FormTemplate::create(['name' => 'Current', 'fa_subclass_code' => 'RC', 'is_active' => true]);

        // Explicit conflict check in the action -> 409, not a raw DB 500.
        $this->actingAs($this->admin)->postJson("/api/admin/wo-forms/templates/{$inactive->id}/reactivate")
            ->assertStatus(409);

        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_reactivation_succeeds_when_subclass_is_free(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'RF', 'type_code' => 'ABC']);

        $template = FormTemplate::create(['name' => 'Solo', 'fa_subclass_code' => 'RF', 'is_active' => false]);

        $this->actingAs($this->admin)->postJson("/api/admin/wo-forms/templates/{$template->id}/reactivate")
            ->assertOk();

        $this->assertTrue($template->fresh()->is_active);
    }
}
