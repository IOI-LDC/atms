<?php

namespace Tests\Feature\Settings;

use App\Enums\RoleCode;
use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_read_company_settings(): void
    {
        $this->getJson('/api/admin/company-settings')
            ->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_update_company_settings(): void
    {
        $this->patchJson('/api/admin/company-settings', [
            'timezone' => 'Europe/Berlin',
        ])
            ->assertStatus(401);
    }

    public function test_company_settings_seed_default_timezone(): void
    {
        $this->seed();

        $setting = CompanySetting::first();
        $this->assertEquals('Africa/Tripoli', $setting->timezone);
    }

    public function test_company_settings_can_be_updated(): void
    {
        CompanySetting::create(['timezone' => 'Africa/Tripoli']);

        $setting = CompanySetting::first();
        $setting->update(['timezone' => 'Europe/Berlin']);

        $this->assertEquals('Europe/Berlin', $setting->fresh()->timezone);
    }

    public function test_administrator_can_read_company_settings(): void
    {
        $this->seed();
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/company-settings')
            ->assertOk()
            ->assertJsonStructure(['timezone']);
    }

    public function test_administrator_can_update_valid_timezone(): void
    {
        $this->seed();
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->patchJson('/api/admin/company-settings', [
                'timezone' => 'Europe/Berlin',
            ])
            ->assertOk()
            ->assertJson(['timezone' => 'Europe/Berlin']);
    }

    public function test_non_admin_cannot_read_company_settings(): void
    {
        $this->seed();
        $user = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::REQUESTER)->first()->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/admin/company-settings')
            ->assertStatus(403);
    }

    public function test_non_admin_cannot_update_company_settings(): void
    {
        $this->seed();
        $user = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::REQUESTER)->first()->id,
        ]);

        $this->actingAs($user)
            ->patchJson('/api/admin/company-settings', [
                'timezone' => 'Europe/Berlin',
            ])
            ->assertStatus(403);
    }

    public function test_update_rejects_invalid_timezone_with_422(): void
    {
        $this->seed();
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->patchJson('/api/admin/company-settings', [
                'timezone' => 'Invalid/Timezone',
            ])
            ->assertStatus(422);
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }
}
