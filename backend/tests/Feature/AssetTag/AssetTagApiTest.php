<?php

namespace Tests\Feature\AssetTag;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetTagApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createUser(RoleCode $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $role)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createAsset(): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-TAG-'.uniqid(),
            'name' => 'Tag Test Asset',
            'is_active' => true,
        ]);
    }

    public function test_suggest_tag_returns_tag_for_admin(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/suggest-tag");

        $response->assertOk()
            ->assertJsonStructure(['asset_tag', 'collision', 'generated_at']);
    }

    public function test_tag_is_immutable_for_non_admin(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-001',
            'name' => 'Asset',
            'asset_tag' => 'L-MTR-8000011',
            'is_active' => true,
        ]);

        $this->actingAs($manager)->patchJson("/api/assets/{$asset->id}", [
            'asset_tag' => 'L-MTR-8000022',
        ])->assertStatus(422)
            ->assertJsonPath('errors.asset_tag.0', 'Asset tag is immutable after creation.');
    }

    public function test_tag_override_allowed_for_admin_with_reason(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-002',
            'name' => 'Asset',
            'asset_tag' => 'L-MTR-8000011',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->patchJson("/api/assets/{$asset->id}", [
            'asset_tag' => 'L-MTR-8000022',
            'asset_tag_override_reason' => 'Corrected typo',
        ])->assertOk();

        $this->assertEquals('L-MTR-8000022', $asset->fresh()->asset_tag);
    }

    public function test_tag_cannot_be_cleared_even_by_admin(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-003',
            'name' => 'Asset',
            'asset_tag' => 'L-MTR-8000011',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->patchJson("/api/assets/{$asset->id}", [
            'asset_tag' => null,
            'asset_tag_override_reason' => 'Trying to clear',
        ])->assertStatus(422)
            ->assertJsonPath('errors.asset_tag.0', 'Cannot clear an existing asset tag.');
    }

    public function test_qr_lookup_finds_asset_by_tag(): void
    {
        $user = $this->createUser(RoleCode::REQUESTER);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-004',
            'name' => 'Asset',
            'asset_tag' => 'L-MTR-8000011',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/assets/by-tag?tag=L-MTR-8000011');

        $response->assertOk()
            ->assertJsonPath('data.id', $asset->id)
            ->assertJsonPath('data.asset_tag', 'L-MTR-8000011');
    }

    public function test_qr_lookup_returns_404_for_missing_tag(): void
    {
        $user = $this->createUser(RoleCode::REQUESTER);

        $this->actingAs($user)->getJson('/api/assets/by-tag?tag=L-XXX-0000000')
            ->assertNotFound();
    }
}
