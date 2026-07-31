<?php

namespace Tests\Feature\Attachments;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Attachments stay open on a Maintenance Request at every workflow stage.
 *
 * Workflow fields lock once the MR leaves pending_review, but the evidence
 * trail does not — a Technician can still attach a photo to a request that has
 * already been converted into a Work Order.
 */
class MaintenanceRequestAttachmentStageTest extends TestCase
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

    private function createMaintenanceRequest(User $creator, MaintenanceRequestStatus $status = MaintenanceRequestStatus::PENDING_REVIEW): MaintenanceRequest
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-STAGE-'.uniqid(),
            'name' => 'Test Asset',
            'is_active' => true,
        ]);

        return MaintenanceRequest::create([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::count() + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $asset->id,
            'status' => $status->value,
            'priority' => 'medium',
            'description' => 'Test MR',
            'created_by' => $creator->id,
            'is_preventive' => false,
        ]);
    }

    /**
     * Built with create() rather than image() — the container has no GD
     * extension, which is what the rest of the attachment suite does too.
     */
    private function imageFile(): UploadedFile
    {
        return UploadedFile::fake()->create('evidence.jpg', 100, 'image/jpeg');
    }

    private function upload(User $user, MaintenanceRequest $mr): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson("/api/maintenance-requests/{$mr->id}/attachments", [
            'file' => $this->imageFile(),
        ]);
    }

    /**
     * @return array<string, array{MaintenanceRequestStatus}>
     */
    public static function everyStatusProvider(): array
    {
        return [
            'pending_review' => [MaintenanceRequestStatus::PENDING_REVIEW],
            'converted' => [MaintenanceRequestStatus::CONVERTED],
            'rejected' => [MaintenanceRequestStatus::REJECTED],
            'cancelled' => [MaintenanceRequestStatus::CANCELLED],
        ];
    }

    #[DataProvider('everyStatusProvider')]
    public function test_creator_can_upload_at_any_status(MaintenanceRequestStatus $status): void
    {
        $creator = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($creator, $status);

        $this->upload($creator, $mr)->assertCreated();
    }

    #[DataProvider('everyStatusProvider')]
    public function test_manager_can_upload_at_any_status(MaintenanceRequestStatus $status): void
    {
        $creator = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($creator, $status);

        $this->upload($this->createUser(RoleCode::MAINTENANCE_MANAGER), $mr)->assertCreated();
    }

    /**
     * The regression this policy change fixes: every role can raise an MR, but
     * the old rule only let a user holding RoleCode::REQUESTER upload to their
     * own — so a Technician was locked out of the request they had filed.
     */
    public function test_technician_creator_can_upload_to_their_own_mr(): void
    {
        $technician = $this->createUser(RoleCode::TECHNICIAN);
        $mr = $this->createMaintenanceRequest($technician);

        $this->upload($technician, $mr)->assertCreated();
    }

    public function test_technician_creator_can_upload_after_conversion(): void
    {
        $technician = $this->createUser(RoleCode::TECHNICIAN);
        $mr = $this->createMaintenanceRequest($technician, MaintenanceRequestStatus::CONVERTED);

        $this->upload($technician, $mr)->assertCreated();
    }

    public function test_logistics_creator_can_upload_to_their_own_mr(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $mr = $this->createMaintenanceRequest($logistics);

        $this->upload($logistics, $mr)->assertCreated();
    }

    #[DataProvider('everyStatusProvider')]
    public function test_non_creator_technician_cannot_upload(MaintenanceRequestStatus $status): void
    {
        $creator = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($creator, $status);

        $this->upload($this->createUser(RoleCode::TECHNICIAN), $mr)->assertForbidden();
    }

    public function test_non_creator_requester_cannot_upload(): void
    {
        $creator = $this->createUser(RoleCode::REQUESTER);
        $mr = $this->createMaintenanceRequest($creator);

        $this->upload($this->createUser(RoleCode::REQUESTER), $mr)->assertForbidden();
    }

    public function test_creator_can_delete_own_attachment_while_pending(): void
    {
        $creator = $this->createUser(RoleCode::TECHNICIAN);
        $mr = $this->createMaintenanceRequest($creator);
        $this->upload($creator, $mr)->assertCreated();

        $attachment = Attachment::firstOrFail();

        $this->actingAs($creator)
            ->deleteJson("/api/attachments/{$attachment->id}")
            ->assertOk();
    }

    /**
     * Deletion closes when the MR leaves pending_review even though upload
     * stays open — that asymmetry is what preserves the historical record.
     */
    public function test_creator_cannot_delete_own_attachment_once_converted(): void
    {
        $creator = $this->createUser(RoleCode::TECHNICIAN);
        $mr = $this->createMaintenanceRequest($creator);
        $this->upload($creator, $mr)->assertCreated();

        $mr->update(['status' => MaintenanceRequestStatus::CONVERTED->value]);
        $attachment = Attachment::firstOrFail();

        $this->actingAs($creator)
            ->deleteJson("/api/attachments/{$attachment->id}")
            ->assertForbidden();
    }

    public function test_manager_can_delete_after_conversion(): void
    {
        $creator = $this->createUser(RoleCode::TECHNICIAN);
        $mr = $this->createMaintenanceRequest($creator, MaintenanceRequestStatus::CONVERTED);
        $this->upload($creator, $mr)->assertCreated();

        $attachment = Attachment::firstOrFail();

        $this->actingAs($this->createUser(RoleCode::MAINTENANCE_MANAGER))
            ->deleteJson("/api/attachments/{$attachment->id}")
            ->assertOk();
    }
}
