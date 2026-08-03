<?php

namespace Tests\Feature\Parts;

use App\Enums\RoleCode;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartIndexPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    /**
     * The frontend loads the full parts catalogue by following cursor
     * pagination. The cap must be large enough (5000) that a realistic
     * catalogue arrives in a single request — the previous cap of 100 forced
     * one round trip per 100 rows, which dominated load time against the
     * production VPS.
     */
    public function test_large_per_page_returns_more_than_the_old_100_cap_in_one_page(): void
    {
        foreach (range(1, 105) as $i) {
            Part::create([
                'erp_part_code' => 'PRT-CAP-'.$i.'-'.uniqid(),
                'name' => 'Part '.$i,
                'is_active' => true,
            ]);
        }

        $this->actingAs($this->admin())
            ->getJson('/api/parts?per_page=5000')
            ->assertOk()
            ->assertJsonCount(105, 'data')
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_per_page_is_capped_at_5000(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/parts?per_page=999999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 5000);
    }
}
