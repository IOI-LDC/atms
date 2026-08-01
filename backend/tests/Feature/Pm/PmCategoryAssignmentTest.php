<?php

namespace Tests\Feature\Pm;

use App\Actions\Pm\ReconcilePmCategoryAssignments;
use App\Enums\MaintenanceStatus;
use App\Enums\PmAssignmentOrigin;
use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetPmAssignment;
use App\Models\MaintenanceCategory;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-012: a PM rule can cover Maintenance Categories, which expand into one
 * assignment per member asset.
 *
 * The tests below are mostly about the *boundaries* of that expansion, because
 * that is where it can quietly do damage: it must never withdraw a manual
 * assignment, never overrule a person's deliberate opt-out, and never leave a
 * row behind for an asset that has moved out of the category.
 */
class PmCategoryAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function category(string $code): MaintenanceCategory
    {
        return MaintenanceCategory::firstOrCreate(['code' => $code], ['name' => $code, 'is_active' => true]);
    }

    private function asset(?MaintenanceCategory $category = null, array $attributes = []): Asset
    {
        return Asset::create(array_merge([
            'erp_asset_code' => 'AST-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
            'maintenance_category_id' => $category?->id,
        ], $attributes));
    }

    private function rule(array $categoryIds = []): PmRule
    {
        $rule = PmRule::create([
            'name' => 'Rule '.uniqid(),
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        if ($categoryIds !== []) {
            $rule->maintenanceCategories()->sync($categoryIds);
        }

        return $rule;
    }

    private function assignmentFor(PmRule $rule, Asset $asset): ?AssetPmAssignment
    {
        return AssetPmAssignment::where('pm_rule_id', $rule->id)->where('asset_id', $asset->id)->first();
    }

    public function test_covering_a_category_creates_an_assignment_for_every_member_asset(): void
    {
        $motors = $this->category('MOTORS');
        $first = $this->asset($motors);
        $second = $this->asset($motors);
        $unrelated = $this->asset($this->category('JARS'));

        $this->actingAs($this->admin)->postJson('/api/pm-rules', [
            'name' => 'Motor 30-day',
            'trigger_type' => 'date',
            'interval_days' => 30,
            'maintenance_category_ids' => [$motors->id],
        ])->assertCreated();

        $rule = PmRule::where('name', 'Motor 30-day')->firstOrFail();

        $this->assertNotNull($this->assignmentFor($rule, $first));
        $this->assertNotNull($this->assignmentFor($rule, $second));
        $this->assertNull($this->assignmentFor($rule, $unrelated));
        $this->assertSame(PmAssignmentOrigin::CATEGORY, $this->assignmentFor($rule, $first)->origin);
        $this->assertSame($motors->id, $this->assignmentFor($rule, $first)->source_maintenance_category_id);
    }

    /**
     * The reason this design materializes rows rather than resolving them: a
     * newly covered asset must start with a full interval of grace, and there
     * is no moment at which a dynamic resolution could stamp that.
     */
    public function test_an_expanded_assignment_starts_with_a_full_interval_of_grace(): void
    {
        $motors = $this->category('MOTORS');
        $asset = $this->asset($motors);

        $rule = $this->rule([$motors->id]);
        app(ReconcilePmCategoryAssignments::class)->forRule($rule);

        $this->assertSame(
            now()->toDateString(),
            $this->assignmentFor($rule, $asset)->last_triggered_date->toDateString(),
        );
    }

    public function test_only_enrolled_active_assets_are_covered(): void
    {
        $motors = $this->category('MOTORS');
        $enrolled = $this->asset($motors);
        $inactive = $this->asset($motors, ['is_active' => false]);
        $withdrawn = $this->asset($motors, ['maintenance_status' => MaintenanceStatus::WITHDRAWN]);

        $rule = $this->rule([$motors->id]);
        app(ReconcilePmCategoryAssignments::class)->forRule($rule);

        $this->assertNotNull($this->assignmentFor($rule, $enrolled));
        $this->assertNull($this->assignmentFor($rule, $inactive));
        $this->assertNull($this->assignmentFor($rule, $withdrawn));
    }

    public function test_removing_a_category_withdraws_the_rows_it_created(): void
    {
        $motors = $this->category('MOTORS');
        $asset = $this->asset($motors);
        $rule = $this->rule([$motors->id]);
        app(ReconcilePmCategoryAssignments::class)->forRule($rule);

        $this->actingAs($this->admin)
            ->patchJson("/api/pm-rules/{$rule->id}", ['maintenance_category_ids' => []])
            ->assertOk();

        $this->assertFalse($this->assignmentFor($rule, $asset)->is_active);
    }

    public function test_moving_an_asset_out_of_a_covered_category_withdraws_its_assignment(): void
    {
        $motors = $this->category('MOTORS');
        $jars = $this->category('JARS');
        $asset = $this->asset($motors);
        $rule = $this->rule([$motors->id]);
        app(ReconcilePmCategoryAssignments::class)->forRule($rule);

        $this->actingAs($this->admin)
            ->patchJson("/api/assets/{$asset->id}", ['maintenance_category_id' => $jars->id])
            ->assertOk();

        $this->assertFalse($this->assignmentFor($rule, $asset)->is_active);
    }

    public function test_moving_an_asset_into_a_covered_category_creates_its_assignment(): void
    {
        $motors = $this->category('MOTORS');
        $asset = $this->asset($this->category('JARS'));
        $rule = $this->rule([$motors->id]);

        $this->actingAs($this->admin)
            ->patchJson("/api/assets/{$asset->id}", ['maintenance_category_id' => $motors->id])
            ->assertOk();

        $assignment = $this->assignmentFor($rule, $asset);
        $this->assertNotNull($assignment);
        $this->assertTrue($assignment->is_active);
        $this->assertSame(PmAssignmentOrigin::CATEGORY, $assignment->origin);
    }

    /**
     * The precedence rule. Without it, one operator's deliberate opt-out for a
     * single asset silently reverts the next time anything in the category
     * changes.
     */
    public function test_reconciliation_never_restores_what_a_person_deactivated(): void
    {
        $motors = $this->category('MOTORS');
        $asset = $this->asset($motors);
        $rule = $this->rule([$motors->id]);
        $reconciler = app(ReconcilePmCategoryAssignments::class);
        $reconciler->forRule($rule);

        $assignment = $this->assignmentFor($rule, $asset);
        $this->actingAs($this->admin)
            ->postJson("/api/assets/{$asset->id}/pm-assignments/{$assignment->id}/deactivate")
            ->assertOk();

        $reconciler->forRule($rule);

        $this->assertFalse($this->assignmentFor($rule, $asset)->is_active);
    }

    public function test_reconciliation_restores_a_row_it_withdrew_itself(): void
    {
        $motors = $this->category('MOTORS');
        $jars = $this->category('JARS');
        $asset = $this->asset($motors);
        $rule = $this->rule([$motors->id]);
        $reconciler = app(ReconcilePmCategoryAssignments::class);
        $reconciler->forRule($rule);

        // Out of the category, then back into it.
        $asset->update(['maintenance_category_id' => $jars->id]);
        $reconciler->forRule($rule);
        $this->assertFalse($this->assignmentFor($rule, $asset)->is_active);

        $asset->update(['maintenance_category_id' => $motors->id]);
        $reconciler->forRule($rule);

        $this->assertTrue($this->assignmentFor($rule, $asset)->is_active);
    }

    public function test_a_manual_assignment_survives_every_category_change(): void
    {
        $motors = $this->category('MOTORS');
        $jars = $this->category('JARS');
        $asset = $this->asset($jars);
        $rule = $this->rule([$motors->id]);

        // Deliberately assigned to an asset the rule's category does not cover.
        $this->actingAs($this->admin)
            ->postJson("/api/assets/{$asset->id}/pm-assignments", ['pm_rule_id' => $rule->id])
            ->assertCreated();

        app(ReconcilePmCategoryAssignments::class)->forRule($rule);

        $assignment = $this->assignmentFor($rule, $asset);
        $this->assertTrue($assignment->is_active);
        $this->assertSame(PmAssignmentOrigin::MANUAL, $assignment->origin);
    }

    public function test_deactivating_a_rule_withdraws_its_category_rows_and_reactivating_restores_them(): void
    {
        $motors = $this->category('MOTORS');
        $asset = $this->asset($motors);
        $rule = $this->rule([$motors->id]);
        app(ReconcilePmCategoryAssignments::class)->forRule($rule);

        $this->actingAs($this->admin)->postJson("/api/pm-rules/{$rule->id}/deactivate")->assertOk();
        $this->assertFalse($this->assignmentFor($rule, $asset)->is_active);

        $this->actingAs($this->admin)->postJson("/api/pm-rules/{$rule->id}/reactivate")->assertOk();
        $this->assertTrue($this->assignmentFor($rule, $asset)->is_active);
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $motors = $this->category('MOTORS');
        $this->asset($motors);
        $this->asset($motors);
        $rule = $this->rule([$motors->id]);
        $reconciler = app(ReconcilePmCategoryAssignments::class);

        $first = $reconciler->forRule($rule);
        $second = $reconciler->forRule($rule);

        $this->assertSame(2, $first['created']);
        $this->assertSame(['created' => 0, 'restored' => 0, 'withdrawn' => 0, 'skipped' => 0], $second);
        $this->assertSame(2, AssetPmAssignment::where('pm_rule_id', $rule->id)->count());
    }

    /**
     * The edit sheet is opened straight from the rules list and submits the
     * coverage it was handed. If the list omitted `maintenance_categories`, a
     * rename would post an empty array and silently wipe the rule's coverage —
     * which is exactly what happened before the index eager-loaded them.
     */
    public function test_the_rules_list_carries_each_rules_categories(): void
    {
        $motors = $this->category('MOTORS');
        $rule = $this->rule([$motors->id]);

        $this->actingAs($this->admin)
            ->getJson('/api/pm-rules')
            ->assertOk()
            ->assertJsonPath('data.0.maintenance_categories.0.id', $motors->id);

        // And a rename that echoes that coverage back leaves it intact.
        $this->actingAs($this->admin)
            ->patchJson("/api/pm-rules/{$rule->id}", [
                'name' => 'Renamed',
                'maintenance_category_ids' => [$motors->id],
            ])
            ->assertOk();

        $this->assertSame(1, $rule->fresh()->maintenanceCategories()->count());
    }

    public function test_the_rule_payload_exposes_its_categories(): void
    {
        $motors = $this->category('MOTORS');
        $rule = $this->rule([$motors->id]);

        $this->actingAs($this->admin)
            ->getJson("/api/pm-rules/{$rule->id}")
            ->assertOk()
            ->assertJsonPath('data.maintenance_categories.0.code', 'MOTORS');
    }
}
