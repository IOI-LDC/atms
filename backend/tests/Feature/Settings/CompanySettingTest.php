<?php

namespace Tests\Feature\Settings;

use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_read_company_settings(): void
    {
        $this->getJson('/api/admin/company-settings')
            ->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_update_company_settings(): void
    {
        $this->patchJson('/api/admin/company-settings', [
            'timezone' => 'Europe/Berlin',
        ])
            ->assertUnauthorized();
    }

    public function test_company_settings_seed_default_timezone(): void
    {
        CompanySetting::create(['timezone' => 'Africa/Tripoli']);

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

    public function test_company_settings_rejects_invalid_timezone(): void
    {
        CompanySetting::create(['timezone' => 'Africa/Tripoli']);

        $response = $this->patchJson('/api/admin/company-settings', [
            'timezone' => 'Invalid/Timezone',
        ]);

        $response->assertStatus(401);
    }
}
