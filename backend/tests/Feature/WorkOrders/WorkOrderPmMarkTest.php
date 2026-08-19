<?php

namespace Tests\Feature\WorkOrders;

use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetPmAssignment;
use App\Models\AuditLog;
use App\Models\MaintenanceRequest;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPmMark;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RQ1 — marking the PM level performed during a work order.
 *
 * The mark is **staged**: recorded while the team works, applied when the work
 * order closes, discarded if it is cancelled. That is the whole design, and
 * `test_cancelling_discards_the_mark_and_leaves_the_schedule_alone` is the test
 * that justifies it — applying immediately would advance an asset's PM schedule
 * for work that never got signed off.
 */
class WorkOrderPmMarkTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $tech;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->manager = $this->user(RoleCode::MAINTENANCE_MANAGER);
        $this->tech = $this->user(RoleCode::TECHNICIAN);
        $this->asset = Asset::create([
            'erp_asset_code' => 'AST-PM-'.uniqid(),
            'name' => 'Serviced Asset',
            'is_active' => true,
            'current_location_id' => $this->workshopLocation()->id,
        ]);
    }

    private function user(RoleCode $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', $roleCode)->first()->id,
            'is_active' => true,
        ]);
    }

    private function assignment(string $level, ?Asset $asset = null): AssetPmAssignment
    {
        $rule = PmRule::create([
            'name' => 'Service '.$level.' '.uniqid(),
            'maintenance_level' => $level,
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 90,
            'is_active' => true,
            'created_by' => $this->manager->id,
        ]);

        return AssetPmAssignment::create([
            'asset_id' => ($asset ?? $this->asset)->id,
            'pm_rule_id' => $rule->id,
            'is_active' => true,
            'last_triggered_date' => now()->subDays(200)->toDateString(),
        ]);
    }

    /** A work order on $this->asset, driven to IN_PROGRESS. */
    private function inProgressWorkOrder(): WorkOrder
    {
        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $this->asset->id,
            'status' => 'converted',
            'priority' => 'medium',
            'description' => 'Repair',
            'created_by' => $this->user(RoleCode::REQUESTER)->id,
            'is_preventive' => false,
        ]);

        $wo = WorkOrder::create([
            'number' => 'WO-'.uniqid(),
            'maintenance_request_id' => $mr->id,
            'asset_id' => $this->asset->id,
            'status' => 'open',
            'priority' => 'medium',
            'description' => 'Repair',
        ]);

        $this->actingAs($this->manager)
            ->postJson("/api/work-orders/{$wo->id}/assign", ['user_id' => $this->tech->id])->assertOk();
        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        return $wo->fresh();
    }

    private function complete(WorkOrder $wo): WorkOrder
    {
        $this->actingAs($this->tech)
            ->postJson("/api/work-orders/{$wo->id}/complete", ['completion_notes' => 'Done'])->assertOk();
        $this->attachToWorkOrder($wo);

        return $wo->fresh();
    }

    private function mark(User $user, WorkOrder $wo, AssetPmAssignment $assignment)
    {
        return $this->actingAs($user)
            ->putJson("/api/work-orders/{$wo->id}/pm-mark", ['asset_pm_assignment_id' => $assignment->id]);
    }

    // ── Marking ─────────────────────────────────────────────────────────────────

    public function test_the_assigned_technician_can_mark_a_level_during_the_work(): void
    {
        $wo = $this->inProgressWorkOrder();
        $l2 = $this->assignment('L2');

        $this->mark($this->tech, $wo, $l2)
            ->assertOk()
            ->assertJsonPath('data.maintenance_level', 'L2');

        $this->assertSame(1, WorkOrderPmMark::where('work_order_id', $wo->id)->count());
    }

    /**
     * Nothing is applied at marking time. The assignment's baseline must be
     * untouched until the work order actually closes.
     */
    public function test_marking_alone_does_not_touch_the_schedule(): void
    {
        $wo = $this->inProgressWorkOrder();
        $l2 = $this->assignment('L2');
        $baselineBefore = $l2->last_triggered_date?->toDateString();

        $this->mark($this->tech, $wo, $l2)->assertOk();

        $this->assertEquals($baselineBefore, $l2->fresh()->last_triggered_date?->toDateString());
    }

    /** PUT is idempotent — the unique constraint is also the idempotency key. */
    public function test_marking_twice_replaces_rather_than_accumulates(): void
    {
        $wo = $this->inProgressWorkOrder();
        $l1 = $this->assignment('L1');
        $l3 = $this->assignment('L3');

        $this->mark($this->tech, $wo, $l1)->assertOk();
        $this->mark($this->tech, $wo, $l1)->assertOk();
        $this->mark($this->tech, $wo, $l3)->assertOk();

        $marks = WorkOrderPmMark::where('work_order_id', $wo->id)->get();
        $this->assertCount(1, $marks);
        $this->assertSame($l3->id, $marks->first()->asset_pm_assignment_id);
    }

    /**
     * "A double-submit is a no-op" is what the mini-spec promises, and
     * `updateOrCreate` alone does not deliver it: it rewrites `marked_at` and
     * emits a second audit event every time. The log has to answer "who marked
     * this level, and when" — not "who last pressed the button".
     */
    public function test_an_identical_mark_is_a_true_no_op(): void
    {
        $wo = $this->inProgressWorkOrder();
        $l2 = $this->assignment('L2');

        $this->mark($this->tech, $wo, $l2)->assertOk();
        $first = WorkOrderPmMark::where('work_order_id', $wo->id)->first();

        $this->travel(2)->minutes();
        $this->mark($this->tech, $wo, $l2)->assertOk();
        $this->travelBack();

        $again = WorkOrderPmMark::where('work_order_id', $wo->id)->first();

        $this->assertEquals(
            $first->marked_at->toDateTimeString(),
            $again->marked_at->toDateTimeString(),
            'A repeat of the same mark must not restamp who marked it, or when.',
        );
        $this->assertSame(
            1,
            AuditLog::where('event', 'work_order_pm_mark.set')
                ->where('metadata->work_order_id', $wo->id)
                ->count(),
            'Nor add a log entry recording no change.',
        );
    }

    /**
     * A different level is a real change, so it must restamp and re-audit.
     * The no-op above must not swallow it.
     */
    public function test_marking_a_different_level_still_records_the_change(): void
    {
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $this->assignment('L1'))->assertOk();
        $this->mark($this->tech, $wo, $this->assignment('L3'))->assertOk();

        $this->assertSame(
            2,
            AuditLog::where('event', 'work_order_pm_mark.set')
                ->where('metadata->work_order_id', $wo->id)
                ->count(),
        );
    }

    public function test_a_technician_can_clear_their_own_mark(): void
    {
        $wo = $this->inProgressWorkOrder();
        $this->mark($this->tech, $wo, $this->assignment('L2'))->assertOk();

        $this->actingAs($this->tech)->deleteJson("/api/work-orders/{$wo->id}/pm-mark")->assertOk();

        $this->assertSame(0, WorkOrderPmMark::where('work_order_id', $wo->id)->count());
        $this->assertSame(1, AuditLog::where('event', 'work_order_pm_mark.cleared')->count());
    }

    /** Clearing nothing is not an error — the caller's intent is satisfied. */
    public function test_clearing_when_nothing_is_marked_succeeds(): void
    {
        $wo = $this->inProgressWorkOrder();

        $this->actingAs($this->tech)->deleteJson("/api/work-orders/{$wo->id}/pm-mark")->assertOk();
    }

    // ── Guards ──────────────────────────────────────────────────────────────────

    public function test_an_assignment_from_another_asset_is_rejected(): void
    {
        $wo = $this->inProgressWorkOrder();
        $other = Asset::create([
            'erp_asset_code' => 'AST-OTHER-'.uniqid(),
            'name' => 'Someone Else',
            'is_active' => true,
        ]);

        $this->mark($this->tech, $wo, $this->assignment('L2', $other))
            ->assertStatus(409)
            ->assertJsonPath('message', 'That service schedule does not belong to this work order\'s asset.');
    }

    public function test_an_inactive_assignment_is_rejected(): void
    {
        $wo = $this->inProgressWorkOrder();
        $assignment = $this->assignment('L2');
        $assignment->update(['is_active' => false]);

        $this->mark($this->tech, $wo, $assignment)
            ->assertStatus(409)
            ->assertJsonPath('message', 'That service schedule is not active.');
    }

    public function test_an_unassigned_technician_cannot_mark(): void
    {
        $wo = $this->inProgressWorkOrder();
        $stranger = $this->user(RoleCode::TECHNICIAN);

        $this->mark($stranger, $wo, $this->assignment('L2'))->assertForbidden();
    }

    /**
     * A technician's write window ends at completion (the `updateExecution`
     * policy), but a Manager's does not — the paperwork window is still open,
     * and a manager correcting the level before close is exactly the case the
     * mark exists to capture.
     */
    public function test_a_manager_may_still_mark_a_completed_work_order(): void
    {
        $wo = $this->complete($this->inProgressWorkOrder());
        $l2 = $this->assignment('L2');

        $this->mark($this->tech, $wo, $l2)->assertForbidden();
        $this->mark($this->manager, $wo, $l2)->assertOk();
    }

    public function test_a_closed_work_order_cannot_be_marked(): void
    {
        $wo = $this->complete($this->inProgressWorkOrder());
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->mark($this->manager, $wo->fresh(), $this->assignment('L2'))->assertForbidden();
    }

    /**
     * Clearing has to close the same door marking does. It used to lock only the
     * mark row, never the work order, so the status check ran against nothing
     * held — a clear could pass authorisation on a completed order, race a
     * concurrent close, and delete the mark just before close consumed it. The
     * schedule then silently failed to advance for work that was performed.
     */
    public function test_a_closed_work_order_cannot_have_its_mark_cleared(): void
    {
        $wo = $this->inProgressWorkOrder();
        $l2 = $this->assignment('L2');
        $this->mark($this->tech, $wo, $l2)->assertOk();

        $this->complete($wo);
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->actingAs($this->manager)
            ->deleteJson("/api/work-orders/{$wo->id}/pm-mark")
            ->assertForbidden();
    }

    // ── Applied at close ────────────────────────────────────────────────────────

    public function test_closing_applies_the_mark_and_cascades_to_lower_levels(): void
    {
        $l1 = $this->assignment('L1');
        $l2 = $this->assignment('L2');
        $l3 = $this->assignment('L3');
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l3)->assertOk();
        $this->complete($wo);
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $today = now()->toDateString();
        $this->assertEquals($today, $l3->fresh()->last_triggered_date?->toDateString(), 'The marked level resets.');
        $this->assertEquals($today, $l2->fresh()->last_triggered_date?->toDateString(), 'And everything below it.');
        $this->assertEquals($today, $l1->fresh()->last_triggered_date?->toDateString());
    }

    /**
     * The regression the old `/^L([1-4])$/` bound would have caused: levels are
     * a free string and the rule form already offers arbitrary ones.
     */
    public function test_levels_above_l4_cascade_correctly(): void
    {
        $l4 = $this->assignment('L4');
        $l10 = $this->assignment('L10');
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l10)->assertOk();
        $this->complete($wo);
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertEquals(now()->toDateString(), $l4->fresh()->last_triggered_date?->toDateString());
    }

    /**
     * A custom level has no defined ordering against `L2`, so it cascades to
     * nothing. Deliberate, unlike the old numeric bound.
     */
    public function test_a_custom_level_cascades_to_nothing(): void
    {
        $l1 = $this->assignment('L1');
        $seasonal = $this->assignment('SEASONAL');
        $baseline = $l1->last_triggered_date?->toDateString();
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $seasonal)->assertOk();
        $this->complete($wo);
        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertEquals(now()->toDateString(), $seasonal->fresh()->last_triggered_date?->toDateString(), 'Itself, yes.');
        $this->assertEquals($baseline, $l1->fresh()->last_triggered_date?->toDateString(), 'But nothing else.');
    }

    // ── Cancel discards it. The reason the model is staged. ─────────────────────

    public function test_cancelling_discards_the_mark_and_leaves_the_schedule_alone(): void
    {
        $l2 = $this->assignment('L2');
        $baseline = $l2->last_triggered_date?->toDateString();
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l2)->assertOk();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'Parts never arrived.',
            'asset_status' => 'failure',
        ])->assertOk();

        $this->assertEquals(
            $baseline,
            $l2->fresh()->last_triggered_date?->toDateString(),
            'A cancelled job must not advance the schedule — this is why marks are staged.',
        );
        $this->assertSame(0, WorkOrderPmMark::where('work_order_id', $wo->id)->count());
        $this->assertSame(1, AuditLog::where('event', 'work_order_pm_mark.discarded')->count());
    }

    // ── Conflict and failure handling ───────────────────────────────────────────

    public function test_the_close_payload_overrides_a_staged_mark_and_audits_it(): void
    {
        $l1 = $this->assignment('L1');
        $l3 = $this->assignment('L3');
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l1)->assertOk();
        $this->complete($wo);

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close", [
            'serviced_pm_assignment_id' => $l3->id,
        ])->assertOk();

        $log = AuditLog::where('event', 'work_order_pm_mark.superseded')->sole();
        $this->assertSame($l1->id, $log->metadata['marked_assignment_id']);
        $this->assertSame($l3->id, $log->metadata['closed_with_assignment_id']);
    }

    /**
     * A schedule deactivated between marking and closing is skipped, audited and
     * reported — never allowed to block the close. Same principle as
     * `ConfirmWorkOrderReadings`: a data-quality problem must not stop an
     * operational transition.
     */
    public function test_a_mark_whose_schedule_was_deactivated_is_skipped_with_a_warning(): void
    {
        $l2 = $this->assignment('L2');
        $baseline = $l2->last_triggered_date?->toDateString();
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l2)->assertOk();
        $this->complete($wo);
        $l2->update(['is_active' => false]);

        $response = $this->actingAs($this->manager)
            ->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame('closed', $wo->fresh()->status->value);
        $this->assertEquals($baseline, $l2->fresh()->last_triggered_date?->toDateString());
        $this->assertSame(1, AuditLog::where('event', 'work_order_pm_mark.skipped')->count());
        $this->assertStringContainsString('no longer active', $response->json('warnings.0'));
    }

    // ── The three states of serviced_pm_assignment_id ───────────────────────────

    /**
     * Omitted, an integer and an explicit null are three different instructions.
     *
     * Only two of them existed before: "unticked" in the close dialog omitted
     * the field, and the backend read omission as "use the staged mark" — so
     * unticking applied the mark anyway and the control did nothing at all.
     */
    public function test_omitting_the_field_applies_the_staged_mark(): void
    {
        $l2 = $this->assignment('L2');
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l2)->assertOk();
        $this->complete($wo);

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame(now()->toDateString(), $l2->fresh()->last_triggered_date?->toDateString());
    }

    public function test_an_explicit_null_applies_no_pm_level_at_all(): void
    {
        $l2 = $this->assignment('L2');
        $baseline = $l2->last_triggered_date?->toDateString();
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l2)->assertOk();
        $this->complete($wo);

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close", [
            'serviced_pm_assignment_id' => null,
        ])->assertOk();

        $this->assertEquals(
            $baseline,
            $l2->fresh()->last_triggered_date?->toDateString(),
            'Suppression must leave the schedule exactly where it was.',
        );

        $log = AuditLog::where('event', 'work_order_pm_mark.suppressed')->sole();
        $this->assertSame($l2->id, $log->metadata['marked_assignment_id']);
    }

    /**
     * The cross-consistency case, and the reason the tri-state is resolved once
     * rather than derived separately by each consumer.
     *
     * The Need Inspection warning used to ask the mark table directly. A close
     * that explicitly suppressed the mark applied nothing — yet the row still
     * existed, so the warning stayed silent on precisely the close it exists
     * for: an asset flagged for inspection, signed off with no PM recorded.
     */
    public function test_a_suppressed_close_still_warns_about_need_inspection(): void
    {
        $l2 = $this->assignment('L2');
        $this->asset->update(['condition_status' => 'need_inspection']);
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l2)->assertOk();
        $this->complete($wo);

        $response = $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close", [
            'serviced_pm_assignment_id' => null,
        ])->assertOk();

        $this->assertStringContainsString('Need Inspection', $response->json('warnings.0'));
    }

    /**
     * Suppression short-circuits before the staged mark is examined, so a
     * deactivated schedule raises nothing — there is no attempt to apply it and
     * therefore nothing that failed.
     */
    public function test_suppressing_a_deactivated_mark_warns_about_nothing(): void
    {
        $l2 = $this->assignment('L2');
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l2)->assertOk();
        $this->complete($wo);
        $l2->update(['is_active' => false]);

        $response = $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close", [
            'serviced_pm_assignment_id' => null,
        ])->assertOk();

        $this->assertSame([], $response->json('warnings'));
        $this->assertSame(0, AuditLog::where('event', 'work_order_pm_mark.skipped')->count());
    }

    /**
     * The asymmetry that makes the tri-state worth having.
     *
     * A deactivated schedule reached through a **staged mark** is skipped with a
     * warning — nobody chose that id at close time, so refusing the close would
     * punish the wrong person. The same schedule named **explicitly in the
     * payload** is a 409: the closer typed it, so they can fix it. Sending the
     * staged mark back as an explicit override, which the close dialog used to
     * do, turned every one of the first case into the second.
     */
    public function test_an_explicit_deactivated_assignment_is_refused(): void
    {
        $l2 = $this->assignment('L2');
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l2)->assertOk();
        $this->complete($wo);
        $l2->update(['is_active' => false]);

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close", [
            'serviced_pm_assignment_id' => $l2->id,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That service schedule is not active.');

        $this->assertSame('completed', $wo->fresh()->status->value, 'A refused close changes nothing.');
    }

    // ── The close warning narrows ───────────────────────────────────────────────

    /**
     * 4b warned whenever a Need Inspection asset was closed. Phase 6 adds the
     * half the design always wanted: if a level *was* recorded, the inspection
     * is accounted for and there is nothing to say.
     */
    public function test_a_marked_pm_silences_the_need_inspection_warning(): void
    {
        $l2 = $this->assignment('L2');
        $this->asset->update(['condition_status' => 'need_inspection']);
        $wo = $this->inProgressWorkOrder();

        $this->mark($this->tech, $wo, $l2)->assertOk();
        $this->complete($wo);

        $response = $this->actingAs($this->manager)
            ->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertSame([], $response->json('warnings'));
    }

    public function test_an_unmarked_close_still_warns_about_need_inspection(): void
    {
        $this->asset->update(['condition_status' => 'need_inspection']);
        $wo = $this->complete($this->inProgressWorkOrder());

        $response = $this->actingAs($this->manager)
            ->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        $this->assertStringContainsString('no PM level was recorded', $response->json('warnings.0'));
    }

    public function test_the_mark_is_exposed_on_the_work_order_payload(): void
    {
        $wo = $this->inProgressWorkOrder();
        $this->mark($this->tech, $wo, $this->assignment('L2'))->assertOk();

        $this->actingAs($this->manager)->getJson("/api/work-orders/{$wo->id}")
            ->assertOk()
            ->assertJsonPath('data.pm_mark.maintenance_level', 'L2');
    }
}
