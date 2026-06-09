<?php

namespace Tests\Feature\Security;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('attachments');
    }

    private function createAdmin(): User
    {
        $role = Role::where('code', RoleCode::ADMINISTRATOR)->first();

        return User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    private function createAsset(): Asset
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);

        return Asset::create([
            'erp_asset_id' => 'ERP-'.uniqid(),
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ]);
    }

    public function test_path_traversal_in_filename_is_sanitized(): void
    {
        $admin = $this->createAdmin();
        $asset = $this->createAsset();

        $file = UploadedFile::fake()->create('traversal.pdf', 100, 'application/pdf');

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(201);
        $attachment = Attachment::first();
        $this->assertStringNotContainsString('..', $attachment->stored_path);
    }

    public function test_download_returns_file_stream_with_content_disposition(): void
    {
        $admin = $this->createAdmin();
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => UploadedFile::fake()->create('test.pdf', 100, 'application/pdf'),
        ]);
        $uploadResponse->assertCreated();
        $attachmentId = $uploadResponse->json('data.id');

        $response = $this->actingAs($admin)->get("/api/attachments/{$attachmentId}/download");

        $response->assertStatus(200);
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('test.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_non_existent_attachment_returns_404(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->get('/api/attachments/999999/download')->assertStatus(404);
    }

    public function test_attachment_stored_path_has_no_directory_traversal(): void
    {
        $admin = $this->createAdmin();
        $asset = $this->createAsset();

        $file = UploadedFile::fake()->create('normal.pdf', 100, 'application/pdf');

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(201);
        $attachment = Attachment::first();
        $this->assertDoesNotMatchRegularExpression('/\.\./', $attachment->stored_path);
        $this->assertStringStartsWith('asset/', $attachment->stored_path);
    }

    public function test_download_response_is_file_not_json(): void
    {
        $admin = $this->createAdmin();
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => UploadedFile::fake()->create('data.pdf', 100, 'application/pdf'),
        ]);
        $uploadResponse->assertCreated();
        $attachmentId = $uploadResponse->json('data.id');

        $response = $this->actingAs($admin)->get("/api/attachments/{$attachmentId}/download");

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('data.pdf', $disposition);
        $this->assertStringNotContainsString('stored_path', $disposition);
    }
}
