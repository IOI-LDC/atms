<?php

namespace Tests\Feature\Attachments;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
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
 * RQ2 (confirmed with the user 2026-08-16): a work order cannot be **closed**
 * until it carries at least one attachment.
 *
 * The gate is on close, not completion, and that ordering is the whole design.
 * Completion is the technician saying the physical work is finished — often
 * from a yard or a rig, where uploading a file is awkward. Closing is the
 * manager signing it off. Between those two moments the paperwork arrives,
 * which is why uploads stay open on a COMPLETED work order and why the
 * technician who did the job is still the one who can supply the evidence.
 *
 * Any attachment satisfies the gate. ATMS has no concept of "the inspection
 * form" specifically — that would need an attachment category that does not
 * exist — so this is a presence check, deliberately.
 */
class WorkOrderAttachmentGateTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('attachments');

        $this->manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $this->tech = $this->user(RoleCode::TECHNICIAN);
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

    // ── The gate ────────────────────────────────────────────────────────────────

    public function test_a_work_order_with_no_attachment_cannot_be_closed(): void
    {
        $wo = $this->completedWorkOrder();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'This work order has no attachments. Upload the completed form or supporting document before closing it.',
            );

        $this->assertSame(
            WorkOrderStatus::COMPLETED,
            $wo->fresh()->status,
            'A refused close must not half-apply.',
        );
    }

    public function test_closing_succeeds_once_something_is_attached(): void
    {
        $wo = $this->completedWorkOrder();

        $this->upload($this->tech, $wo, UploadedFile::fake()->create('inspection.pdf', 40, 'application/pdf'))
            ->assertCreated();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame(WorkOrderStatus::CLOSED, $wo->fresh()->status);
    }

    /**
     * The asset must be untouched by a refused close — the guard runs before
     * every mutation in `CloseWorkOrder`, and this pins that rather than
     * trusting the ordering to stay put.
     */
    public function test_a_refused_close_changes_nothing_about_the_asset(): void
    {
        $wo = $this->completedWorkOrder();
        $wo->asset->update(['condition_status' => 'missing_parts']);

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertStatus(409);

        $this->assertSame('missing_parts', $wo->asset->fresh()->condition_status);
        $this->assertSame('under_maintenance', $wo->asset->fresh()->operational_status->value);
    }

    // ── Uploading after completion — the point of gating close, not complete ────

    /**
     * The requirement in one test: the technician marks the work done, *then*
     * uploads the paperwork. Before 2026-08-16 the SPA hid the upload control at
     * completion, so the person who did the job could not supply the evidence
     * the close now demands.
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
     * locked; until 2026-08-16 nothing enforced it. Tightened here because the
     * close gate makes a post-close upload meaningless anyway.
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
