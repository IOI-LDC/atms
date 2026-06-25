<?php

namespace Tests\Feature\ReadModels;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAttachment(User $uploader): Attachment
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_code' => 'A-001',
            'name' => 'Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ]);

        return $asset->attachments()->create([
            'original_name' => 'doc.pdf',
            'stored_path' => 'attachments/test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'file_hash' => hash('sha256', 'test'),
            'description' => 'Test doc',
            'uploaded_by_user_id' => $uploader->id,
        ]);
    }

    public function test_admin_sees_uploaded_by(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $attachment = $this->createAttachment($admin);

        $response = $this->actingAs($admin)->getJson("/api/assets/{$attachment->attachable_id}/attachments");

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('uploaded_by', $data);
        $this->assertArrayHasKey('download_url', $data);
    }

    public function test_requester_sees_download_url(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $attachment = $this->createAttachment($admin);
        $requester = $this->createUser(RoleCode::REQUESTER);

        $response = $this->actingAs($requester)->getJson("/api/assets/{$attachment->attachable_id}/attachments");

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('download_url', $data);
        $this->assertArrayHasKey('file_name', $data);
    }

    public function test_technician_sees_download_url_but_not_uploaded_by(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $attachment = $this->createAttachment($admin);
        $tech = $this->createUser(RoleCode::TECHNICIAN);

        $response = $this->actingAs($tech)->getJson("/api/assets/{$attachment->attachable_id}/attachments");

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('download_url', $data);
        $this->assertArrayNotHasKey('uploaded_by', $data);
    }

    public function test_attachment_fields_mapping(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $attachment = $this->createAttachment($admin);

        $response = $this->actingAs($admin)->getJson("/api/assets/{$attachment->attachable_id}/attachments");

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertEquals('doc.pdf', $data['file_name']);
        $this->assertEquals('application/pdf', $data['mime_type']);
        $this->assertEquals(100, $data['size_bytes']);
        $this->assertEquals('Test doc', $data['description']);
    }
}
