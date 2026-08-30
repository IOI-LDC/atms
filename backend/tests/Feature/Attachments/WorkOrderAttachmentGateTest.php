<?php

namespace Tests\Feature\Attachments;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Attachments are OPTIONAL at close (loosened 2026-08-30, superseding the RQ2
 * gate of 2026-08-16): a work order closes whether or not it carries one.
 *
 * Everything else about the upload window is unchanged. Uploads stay open on a
 * COMPLETED work order — the technician marks the work finished, then files the
 * paperwork — and lock once the work order is closed or cancelled, as does
 * deletion. Cancelling never needed one.
 */
class WorkOrderAttachmentGateTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $tech;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('attachments');

        $this->manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $this->tech = $this->user(RoleCode::TECHNICIAN);
        $this->admin = $this->user(RoleCode::ADMINISTRATOR);
    }

    private function user(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    /** A work order driven to COMPLETED, with nothing attached. */
    private function completedWorkOrder(): WorkOrder
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-ATT-'.uniqid(),
            'name' => 'Attachment Asset',
            'is_active' => true,
            'current_location_id' => $this->workshopLocation()->id,
        ]);

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'medium',
            'description' => 'Repair',
            'created_by' => $this->user(RoleCode::REQUESTER)->id,
            'is_preventive' => false,
        ]);

        $wo = WorkOrder::create([
            'number' => 'WO-'.uniqid(),
            'maintenance_request_id' => $mr->id,
            'asset_id' => $asset->id,
            'status' => 'open',
            'priority' => 'medium',
            'description' => 'Repair',
        ]);

        $this->actingAs($this->manager)
            ->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $this->tech->id])->assertOk();
        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();
        $this->actingAs($this->tech)
            ->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();

        return $wo->fresh();
    }

    private function upload(User $user, WorkOrder $workOrder, UploadedFile $file)
    {
        return $this->actingAs($user)
            ->post("/api/work-orders/{$workOrder->id}/attachments", ['file' => $file]);
    }

    // ── Attachments are optional at close ──────────────────────────────────────

    public function test_a_work_order_with_no_attachment_can_be_closed(): void
    {
        $wo = $this->completedWorkOrder();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame(WorkOrderStatus::CLOSED, $wo->fresh()->status);
    }

    public function test_closing_succeeds_once_something_is_attached(): void
    {
        $wo = $this->completedWorkOrder();

        $this->upload($this->tech, $wo, UploadedFile::fake()->create('inspection.pdf', 40, 'application/pdf'))
            ->assertCreated();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame(WorkOrderStatus::CLOSED, $wo->fresh()->status);
    }

    // ── Uploading after completion ─────────────────────────────────────────────

    /**
     * The working order in one test: the technician marks the work done, *then*
     * uploads the paperwork. Before 2026-08-16 the SPA hid the upload control at
     * completion, so the person who did the job could not file the evidence for
     * it afterwards.
     */
    public function test_the_assigned_technician_can_upload_to_a_completed_work_order(): void
    {
        $wo = $this->completedWorkOrder();

        $this->upload($this->tech, $wo, UploadedFile::fake()->create('form.pdf', 20, 'application/pdf'))
            ->assertCreated();

        $this->assertSame(1, $wo->fresh()->attachments()->count());
    }

    public function test_a_manager_can_upload_to_a_completed_work_order(): void
    {
        $wo = $this->completedWorkOrder();

        $this->upload($this->manager, $wo, UploadedFile::fake()->create('form.pdf', 20, 'application/pdf'))
            ->assertCreated();
    }

    /**
     * The file types LDC actually sends. No mime allowlist is enforced —
     * restricting to PDF and spreadsheets would reject the photographs the team
     * already attaches — so this pins that the common cases work rather than
     * that anything else is refused.
     *
     * @return array<string, array{string, string}>
     */
    public static function fileTypeProvider(): array
    {
        return [
            'pdf' => ['inspection-form.pdf', 'application/pdf'],
            'excel' => ['readings.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'legacy excel' => ['readings.xls', 'application/vnd.ms-excel'],
            'photo' => ['bearing.jpg', 'image/jpeg'],
        ];
    }

    #[DataProvider('fileTypeProvider')]
    public function test_the_usual_document_types_are_accepted(string $name, string $mime): void
    {
        $wo = $this->completedWorkOrder();

        $this->upload($this->tech, $wo, UploadedFile::fake()->create($name, 50, $mime))->assertCreated();
    }

    // ── Terminal work orders stay locked ────────────────────────────────────────

    /**
     * The user manual has always said a closed work order's attachments are
     * locked; until 2026-08-16 nothing enforced it. A closed work order is a
     * finished record — nothing may be added to it afterwards.
     */
    public function test_a_closed_work_order_rejects_further_uploads(): void
    {
        $wo = $this->completedWorkOrder();
        $this->upload($this->tech, $wo, UploadedFile::fake()->create('form.pdf', 20, 'application/pdf'))->assertCreated();
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->upload($this->tech, $wo->fresh(), UploadedFile::fake()->create('late.pdf', 20, 'application/pdf'))
            ->assertForbidden();
        $this->upload($this->manager, $wo->fresh(), UploadedFile::fake()->create('late.pdf', 20, 'application/pdf'))
            ->assertForbidden();
    }

    /**
     * A work order closed with nothing attached was unreachable before
     * 2026-08-30 and is now ordinary. The terminal lock does not depend on an
     * attachment existing: nothing may be filed against the closed record
     * afterwards, including the first file.
     */
    public function test_a_work_order_closed_with_no_attachment_still_rejects_uploads(): void
    {
        $wo = $this->completedWorkOrder();
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->upload($this->tech, $wo->fresh(), UploadedFile::fake()->create('late.pdf', 20, 'application/pdf'))
            ->assertForbidden();
        $this->upload($this->manager, $wo->fresh(), UploadedFile::fake()->create('late.pdf', 20, 'application/pdf'))
            ->assertForbidden();

        $this->assertSame(0, $wo->fresh()->attachments()->count());
    }

    public function test_a_cancelled_work_order_rejects_further_uploads(): void
    {
        $wo = $this->completedWorkOrder();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'Not needed after all.',
            'asset_status' => 'ready_for_field',
        ])->assertOk();

        $this->upload($this->tech, $wo->fresh(), UploadedFile::fake()->create('late.pdf', 20, 'application/pdf'))
            ->assertForbidden();
    }

    // ── Deletion, the other half of the same invariant ──────────────────────────

    /**
     * Blocking post-close uploads is only half a lock.
     *
     * A closed work order cannot receive an attachment — but deletion stayed
     * open to Admin and Manager at every stage, so the evidence filed for the
     * job could be removed the minute after it closed, leaving a closed work
     * order stripped of it and no way to supply any.
     * Attachments are soft-deleted behind a global scope, so it did not even
     * leave a visible gap.
     *
     * Nobody may do this, administrators included: there is no legitimate reason
     * to, and no way to undo it.
     */
    public function test_a_closed_work_orders_attachment_cannot_be_deleted(): void
    {
        $wo = $this->completedWorkOrder();
        $attachmentId = $this->upload($this->tech, $wo, UploadedFile::fake()->create('form.pdf', 20, 'application/pdf'))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->actingAs($this->manager)->deleteJson("/api/attachments/{$attachmentId}")->assertForbidden();
        $this->actingAs($this->admin)->deleteJson("/api/attachments/{$attachmentId}")->assertForbidden();

        $this->assertNull(
            Attachment::withoutGlobalScopes()->find($attachmentId)->deleted_at,
            'The evidence for a closed work order must survive.',
        );
    }

    public function test_a_cancelled_work_orders_attachment_cannot_be_deleted(): void
    {
        $wo = $this->completedWorkOrder();
        $attachmentId = $this->upload($this->tech, $wo, UploadedFile::fake()->create('form.pdf', 20, 'application/pdf'))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'Not needed after all.',
            'asset_status' => 'ready_for_field',
        ])->assertOk();

        $this->actingAs($this->manager)->deleteJson("/api/attachments/{$attachmentId}")->assertForbidden();
    }

    /**
     * The counterweight: a work order still in flight is a working document, and
     * a mistaken upload must stay removable.
     */
    public function test_an_open_work_orders_attachment_can_still_be_deleted(): void
    {
        $wo = $this->completedWorkOrder();
        $attachmentId = $this->upload($this->tech, $wo, UploadedFile::fake()->create('wrong.pdf', 20, 'application/pdf'))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->manager)->deleteJson("/api/attachments/{$attachmentId}")->assertOk();
    }

    /**
     * Cancelling is deliberately NOT gated. A job that never happened has no
     * paperwork to show, and requiring a file to abandon a work order would
     * strand it.
     */
    public function test_cancelling_needs_no_attachment(): void
    {
        $wo = $this->completedWorkOrder();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'Duplicate of another job.',
            'asset_status' => 'ready_for_field',
        ])->assertOk();

        $this->assertSame(WorkOrderStatus::CANCELLED, $wo->fresh()->status);
    }

    public function test_an_unassigned_technician_still_cannot_upload(): void
    {
        $wo = $this->completedWorkOrder();
        $stranger = $this->user(RoleCode::TECHNICIAN);

        $this->upload($stranger, $wo, UploadedFile::fake()->create('form.pdf', 20, 'application/pdf'))
            ->assertForbidden();
    }
}
