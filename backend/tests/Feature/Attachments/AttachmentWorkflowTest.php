<?php

namespace Tests\Feature\Attachments;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\MaintenanceRequest;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        config(['atms.attachment_disk' => 'attachments']);
        Storage::fake('attachments');
    }

    private function createUser(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createAsset(): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-ATT-'.uniqid(),
            'name' => 'Test Asset',
            'is_active' => true,
        ]);
    }

    private function createPart(): Part
    {
        return Part::create([
            'erp_part_code' => 'PRT-ATT-'.uniqid(),
            'name' => 'Test Part',
            'is_active' => true,
        ]);
    }

    private function createMaintenanceRequest(User $requester, ?Asset $asset = null): MaintenanceRequest
    {
        $asset = $asset ?? $this->createAsset();

        return MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => 'pending_review',
            'priority' => 'medium',
            'description' => 'Test MR',
            'created_by' => $requester->id,
            'is_preventive' => false,
        ]);
    }

    private function createWorkOrder(User $requester, User $manager, ?Asset $asset = null): WorkOrder
    {
        $mr = $this->createMaintenanceRequest($requester, $asset);
        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])->assertOk();

        return WorkOrder::where('maintenance_request_id', $mr->id)->first();
    }

    private function pdfFile(): UploadedFile
    {
        return UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    }

    private function imageFile(): UploadedFile
    {
        return UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');
    }

    private function oversizedFile(): UploadedFile
    {
        return UploadedFile::fake()->create('huge.pdf', 21000, 'application/pdf');
    }

    private function exeFile(): UploadedFile
    {
        return UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');
    }

    private function zipFile(): UploadedFile
    {
        return UploadedFile::fake()->create('archive.zip', 100, 'application/zip');
    }

    public function test_admin_can_upload_attachment_to_asset(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
            'description' => 'Installation guide',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['id', 'original_name', 'mime_type', 'size_bytes', 'description']]);
        $this->assertEquals('document.pdf', $response->json('data.original_name'));
        $this->assertEquals('Installation guide', $response->json('data.description'));
    }

    public function test_technician_can_upload_attachment_to_asset(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $this->actingAs($tech)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertCreated();
    }

    public function test_logistics_can_upload_attachment_to_asset(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $asset = $this->createAsset();

        $this->actingAs($logistics)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertCreated();
    }

    public function test_maintenance_manager_can_upload_attachment_to_asset(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $this->actingAs($manager)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertCreated();
    }

    public function test_requester_cannot_upload_attachment_to_asset(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $this->actingAs($requester)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertForbidden();
    }

    public function test_upload_attachment_to_part(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $part = $this->createPart();

        $response = $this->actingAs($admin)->postJson("/api/parts/{$part->id}/attachments", [
            'file' => $this->pdfFile(),
            'description' => 'Datasheet',
        ]);

        $response->assertCreated();
        $this->assertEquals('Datasheet', $response->json('data.description'));
    }

    public function test_requester_owner_can_upload_attachment_to_own_mr(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($requester);

        $this->actingAs($requester)->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
            'file' => $this->imageFile(),
            'description' => 'Damage photo',
        ])->assertCreated();
    }

    public function test_requester_cannot_upload_attachment_to_other_users_mr(): void
    {
        $owner = $this->createUser(RoleCode::REQUESTER);
        $other = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($owner);

        $this->actingAs($other)->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
            'file' => $this->imageFile(),
        ])->assertForbidden();
    }

    public function test_admin_can_upload_attachment_to_any_mr(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $mr = $this->createMaintenanceRequest($requester);

        $this->actingAs($admin)->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
            'file' => $this->imageFile(),
        ])->assertCreated();
    }

    public function test_maintenance_manager_can_upload_attachment_to_any_mr(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $mr = $this->createMaintenanceRequest($requester);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
            'file' => $this->imageFile(),
        ])->assertCreated();
    }

    public function test_assigned_technician_can_upload_attachment_to_wo(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $tech->id,
        ])->assertOk();

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/attachments", [
            'file' => $this->imageFile(),
            'description' => 'Repair evidence',
        ])->assertCreated();
    }

    public function test_unassigned_technician_cannot_upload_attachment_to_wo(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $otherTech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $tech->id,
        ])->assertOk();

        $this->actingAs($otherTech)->postJson("/api/work-orders/{$wo->id}/attachments", [
            'file' => $this->imageFile(),
        ])->assertForbidden();
    }

    public function test_admin_can_upload_attachment_to_wo(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $wo = $this->createWorkOrder($requester, $manager);

        $this->actingAs($admin)->postJson("/api/work-orders/{$wo->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertCreated();
    }

    public function test_requester_cannot_upload_attachment_to_wo(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $otherRequester = $this->createUser(RoleCode::REQUESTER);
        $wo = $this->createWorkOrder($requester, $manager);

        $this->actingAs($otherRequester)->postJson("/api/work-orders/{$wo->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertForbidden();
    }

    public function test_upload_rejects_file_over_20mb(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->oversizedFile(),
        ])->assertUnprocessable();
    }

    public function test_upload_rejects_executable_files(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->exeFile(),
        ])->assertUnprocessable();
    }

    public function test_upload_rejects_archive_files(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->zipFile(),
        ])->assertUnprocessable();
    }

    public function test_upload_detects_mime_type_server_side(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);

        $response->assertCreated();
        $this->assertEquals('application/pdf', $response->json('data.mime_type'));
    }

    public function test_upload_stores_file_hash(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);

        $response->assertCreated();
        $attachment = Attachment::find($response->json('data.id'));
        $this->assertNotNull($attachment->file_hash);
        $this->assertEquals(64, strlen($attachment->file_hash));
    }

    public function test_upload_records_uploader(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);

        $response->assertCreated();
        $attachment = Attachment::find($response->json('data.id'));
        $this->assertEquals($admin->id, $attachment->uploaded_by_user_id);
    }

    public function test_list_asset_attachments(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
            'description' => 'First',
        ])->assertCreated();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
            'description' => 'Second',
        ])->assertCreated();

        $response = $this->actingAs($admin)->getJson("/api/assets/{$asset->id}/attachments");
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_excludes_soft_deleted_attachments(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertCreated();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertCreated();

        $firstId = Attachment::first()->id;
        $this->actingAs($admin)->deleteJson("/api/attachments/{$firstId}")->assertOk();

        $response = $this->actingAs($admin)->getJson("/api/assets/{$asset->id}/attachments");
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_download_attachment(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $uploadResponse->assertCreated();
        $attachmentId = $uploadResponse->json('data.id');

        $response = $this->actingAs($admin)->getJson("/api/attachments/{$attachmentId}/download");
        $response->assertOk();
        $response->assertHeader('content-disposition');
    }

    public function test_download_soft_deleted_attachment_returns_404(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($admin)->deleteJson("/api/attachments/{$attachmentId}")->assertOk();

        $this->actingAs($admin)->getJson("/api/attachments/{$attachmentId}/download")->assertNotFound();
    }

    public function test_unauthenticated_cannot_download(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        auth('sanctum')->forgetUser();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/attachments/{$attachmentId}/download")->assertUnauthorized();
    }

    public function test_soft_delete_attachment(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($admin)->deleteJson("/api/attachments/{$attachmentId}")->assertOk();

        $attachment = Attachment::withoutGlobalScope('not-deleted')->find($attachmentId);
        $this->assertNotNull($attachment->deleted_at);
        $this->assertEquals($admin->id, $attachment->deleted_by_user_id);
    }

    public function test_soft_delete_retains_file_on_disk(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');
        $storedPath = $uploadResponse->json('data.stored_path') ?? Attachment::withoutGlobalScope('not-deleted')->find($attachmentId)->stored_path;

        $this->actingAs($admin)->deleteJson("/api/attachments/{$attachmentId}")->assertOk();

        Storage::disk('attachments')->assertExists($storedPath);
    }

    public function test_maintenance_manager_can_soft_delete(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($manager)->deleteJson("/api/attachments/{$attachmentId}")->assertOk();
    }

    public function test_technician_cannot_soft_delete(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($tech)->deleteJson("/api/attachments/{$attachmentId}")->assertForbidden();
    }

    public function test_requester_cannot_soft_delete(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($requester)->deleteJson("/api/attachments/{$attachmentId}")->assertForbidden();
    }

    public function test_owner_can_delete_own_attachment_while_mr_is_pending(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($requester);

        $uploadResponse = $this->actingAs($requester)->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertCreated();

        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($requester)->deleteJson("/api/attachments/{$attachmentId}")->assertOk();

        $this->assertNotNull(
            Attachment::withoutGlobalScope('not-deleted')->find($attachmentId)->deleted_at
        );
    }

    public function test_owner_cannot_delete_own_attachment_after_mr_converted(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $requester = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($requester);

        $attachmentId = $this->actingAs($requester)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", ['file' => $this->pdfFile()])
            ->assertCreated()
            ->json('data.id');

        // Converting the MR locks its attachments against owner deletion.
        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])->assertOk();

        $this->actingAs($requester)->deleteJson("/api/attachments/{$attachmentId}")->assertForbidden();
    }

    public function test_non_owner_cannot_delete_other_users_attachment_on_pending_mr(): void
    {
        $owner = $this->createUser(RoleCode::REQUESTER);
        $other = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($owner);

        $attachmentId = $this->actingAs($owner)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", ['file' => $this->pdfFile()])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($other)->deleteJson("/api/attachments/{$attachmentId}")->assertForbidden();
    }

    public function test_admin_can_delete_owner_attachment_after_mr_converted(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $requester = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($requester);

        $attachmentId = $this->actingAs($requester)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", ['file' => $this->pdfFile()])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])->assertOk();

        // Admins are still unrestricted after conversion.
        $this->actingAs($admin)->deleteJson("/api/attachments/{$attachmentId}")->assertOk();
    }

    private function canDeleteFor(int $mrId, User $viewer, int $attachmentId): ?bool
    {
        $response = $this->actingAs($viewer)->getJson("/api/maintenance-requests/{$mrId}/attachments")->assertOk();

        return collect($response->json('data'))->firstWhere('id', $attachmentId)['can_delete'] ?? null;
    }

    public function test_can_delete_flag_true_for_owner_on_pending_mr(): void
    {
        $owner = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($owner);

        $attachmentId = $this->actingAs($owner)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", ['file' => $this->pdfFile()])
            ->assertCreated()
            ->json('data.id');

        $this->assertTrue($this->canDeleteFor($mr->id, $owner, $attachmentId));
    }

    public function test_can_delete_flag_false_for_owner_after_mr_converted(): void
    {
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $owner = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($owner);

        $attachmentId = $this->actingAs($owner)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", ['file' => $this->pdfFile()])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])->assertOk();

        $this->assertFalse($this->canDeleteFor($mr->id, $owner, $attachmentId));
    }

    public function test_can_delete_flag_false_for_non_owner_on_pending_mr(): void
    {
        $owner = $this->createUser(RoleCode::REQUESTER);
        $other = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($owner);

        $attachmentId = $this->actingAs($owner)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", ['file' => $this->pdfFile()])
            ->assertCreated()
            ->json('data.id');

        $this->assertFalse($this->canDeleteFor($mr->id, $other, $attachmentId));
    }

    public function test_can_delete_flag_true_for_admin_regardless_of_status(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $owner = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($owner);

        $attachmentId = $this->actingAs($owner)
            ->postJson("/api/maintenance-requests/{$mr->id}/attachments", ['file' => $this->pdfFile()])
            ->assertCreated()
            ->json('data.id');

        // Pending: admin can delete.
        $this->assertTrue($this->canDeleteFor($mr->id, $admin, $attachmentId));

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])->assertOk();

        // Converted: admin still can delete.
        $this->assertTrue($this->canDeleteFor($mr->id, $admin, $attachmentId));
    }

    public function test_cannot_soft_delete_already_deleted_attachment(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($admin)->deleteJson("/api/attachments/{$attachmentId}")->assertOk();
        $this->actingAs($admin)->deleteJson("/api/attachments/{$attachmentId}")->assertNotFound();
    }

    public function test_upload_rejects_missing_file(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [])
            ->assertUnprocessable();
    }

    public function test_description_is_optional(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);

        $response->assertCreated();
        $this->assertNull($response->json('data.description'));
    }

    public function test_file_stored_on_private_disk(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $response->assertCreated();

        $attachment = Attachment::withoutGlobalScope('not-deleted')->find($response->json('data.id'));
        Storage::disk('attachments')->assertExists($attachment->stored_path);
    }

    public function test_stored_path_uses_morph_type_prefix(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $response->assertCreated();

        $attachment = Attachment::find($response->json('data.id'));
        $this->assertStringStartsWith('asset/', $attachment->stored_path);
    }

    public function test_upload_accepts_word_document(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $docx = UploadedFile::fake()->create('report.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $docx,
        ])->assertCreated();
    }

    public function test_upload_accepts_excel_document(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $xlsx = UploadedFile::fake()->create('data.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $xlsx,
        ])->assertCreated();
    }

    public function test_upload_accepts_png_image(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $png = UploadedFile::fake()->create('image.png', 100, 'image/png');

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $png,
        ])->assertCreated();
    }

    public function test_upload_accepts_webp_image(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $webp = UploadedFile::fake()->create('image.webp', 100, 'image/webp');

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $webp,
        ])->assertCreated();
    }

    public function test_upload_rejects_shell_script(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $sh = UploadedFile::fake()->create('script.sh', 100, 'text/x-shellscript');

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $sh,
        ])->assertUnprocessable();
    }

    public function test_upload_rejects_rar_archive(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $rar = UploadedFile::fake()->create('archive.rar', 100, 'application/x-rar-compressed');

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $rar,
        ])->assertUnprocessable();
    }

    public function test_part_upload_allows_admin_manager_tech_logistics(): void
    {
        $roles = [
            RoleCode::ADMINISTRATOR,
            RoleCode::MAINTENANCE_MANAGER,
            RoleCode::TECHNICIAN,
            RoleCode::LOGISTICS,
        ];

        foreach ($roles as $roleCode) {
            $user = $this->createUser($roleCode);
            $part = $this->createPart();

            $this->actingAs($user)->postJson("/api/parts/{$part->id}/attachments", [
                'file' => $this->pdfFile(),
            ])->assertCreated();
        }
    }

    public function test_part_upload_forbids_requester(): void
    {
        $forbidden = [RoleCode::REQUESTER];

        foreach ($forbidden as $roleCode) {
            $user = $this->createUser($roleCode);
            $part = $this->createPart();

            $this->actingAs($user)->postJson("/api/parts/{$part->id}/attachments", [
                'file' => $this->pdfFile(),
            ])->assertForbidden();
        }
    }

    public function test_requester_can_list_asset_attachments(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertCreated();

        $response = $this->actingAs($requester)->getJson("/api/assets/{$asset->id}/attachments");
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_requester_can_download_attachment(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($requester)->getJson("/api/attachments/{$attachmentId}/download")->assertOk();
    }

    public function test_technician_can_list_and_download_wo_attachment(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $wo = $this->createWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $tech->id,
        ])->assertOk();

        $this->actingAs($tech)->postJson("/api/work-orders/{$wo->id}/attachments", [
            'file' => $this->imageFile(),
        ])->assertCreated();

        $this->actingAs($tech)->getJson("/api/work-orders/{$wo->id}/attachments")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $attachmentId = Attachment::first()->id;
        $this->actingAs($tech)->getJson("/api/attachments/{$attachmentId}/download")->assertOk();
    }

    public function test_maintenance_manager_can_upload_attachment_to_wo(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $wo = $this->createWorkOrder($requester, $manager);

        $this->actingAs($manager)->postJson("/api/work-orders/{$wo->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertCreated();
    }

    public function test_no_restore_or_purge_routes_exist(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($admin)->deleteJson("/api/attachments/{$attachmentId}")->assertOk();

        $this->actingAs($admin)->postJson("/api/attachments/{$attachmentId}/restore")->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/attachments/{$attachmentId}/purge")->assertNotFound();
    }

    public function test_logistics_cannot_list_asset_attachments(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $asset = $this->createAsset();

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ])->assertCreated();

        $this->actingAs($logistics)->getJson("/api/assets/{$asset->id}/attachments")
            ->assertForbidden();
    }

    public function test_logistics_cannot_download_asset_attachment(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $asset = $this->createAsset();

        $uploadResponse = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($logistics)->getJson("/api/attachments/{$attachmentId}/download")
            ->assertForbidden();
    }

    public function test_requester_can_download_own_mr_attachment(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($requester);

        $uploadResponse = $this->actingAs($requester)->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($requester)->getJson("/api/attachments/{$attachmentId}/download")
            ->assertOk();
    }

    public function test_requester_can_download_other_users_mr_attachment(): void
    {
        $owner = $this->createUser(RoleCode::REQUESTER);
        $other = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($owner);

        $uploadResponse = $this->actingAs($owner)->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
            'file' => $this->pdfFile(),
        ]);
        $attachmentId = $uploadResponse->json('data.id');

        $this->actingAs($other)->getJson("/api/attachments/{$attachmentId}/download")
            ->assertOk();
    }

    public function test_mime_mismatch_is_rejected_after_server_detection(): void
    {
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $asset = $this->createAsset();

        $tmpPath = tempnam(sys_get_temp_dir(), 'atms_test_');
        file_put_contents($tmpPath, '<html><body>test</body></html>');

        $file = new UploadedFile(
            $tmpPath,
            'document.pdf',
            'application/pdf',
            null,
            true
        );

        $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $file,
        ])->assertUnprocessable();
    }
}
