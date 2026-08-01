<?php

namespace Tests\Feature\Pm;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\PmTriggerType;
use App\Jobs\EvaluatePmRulesJob;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\MaintenanceRequest;
use App\Models\PmOccurrenceSuppression;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the two properties D-013 was about: the nightly evaluation costs a
 * fixed number of queries regardless of how many assignments exist, and the
 * batched readings/suppressions it now uses reach the same verdict the
 * per-assignment queries did.
 *
 * The second half matters as much as the first: `PmDueCalculator`'s batch
 * branches existed for months without a caller, so nothing proved they agreed
 * with the fallback branches beside them.
 */
class PmEvaluationScaleTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->userId = User::factory()->create([
            'email' => 'system@atms.internal',
            'role_id' => Role::first()->id,
            'is_active' => true,
            'activated_at' => now(),
        ])->id;
    }

    private function createAsset(): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
        ]);
    }

    private function createRule(array $attributes = []): PmRule
    {
        return PmRule::create(array_merge([
            'name' => 'Rule '.uniqid(),
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->userId,
        ], $attributes));
    }

    private function assign(Asset $asset, PmRule $rule, array $attributes = []): AssetPmAssignment
    {
        return AssetPmAssignment::create(array_merge([
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'is_active' => true,
            'assigned_by' => $this->userId,
        ], $attributes));
    }

    private function countQueriesDuringEvaluation(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        (new EvaluatePmRulesJob)->handle();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * The regression this whole phase exists for. Before batching, each
     * assignment cost roughly 6–12 queries in its own locked transaction even
     * when nothing was due, so 5 → 25 assignments multiplied the bill fivefold.
     */
    public function test_query_cost_does_not_grow_with_assignment_count(): void
    {
        $rule = $this->createRule();

        // Not due: a full interval of grace remains on every assignment.
        for ($i = 0; $i < 5; $i++) {
            $this->assign($this->createAsset(), $rule, ['last_triggered_date' => now()]);
        }

        $withFive = $this->countQueriesDuringEvaluation();

        for ($i = 0; $i < 20; $i++) {
            $this->assign($this->createAsset(), $rule, ['last_triggered_date' => now()]);
        }

        $withTwentyFive = $this->countQueriesDuringEvaluation();

        $this->assertSame(
            $withFive,
            $withTwentyFive,
            "Evaluating 25 assignments cost {$withTwentyFive} queries against {$withFive} for 5 — the cost is scaling with the register."
        );
        $this->assertLessThan(15, $withTwentyFive, 'Evaluation should cost a small fixed number of queries.');
        $this->assertSame(0, MaintenanceRequest::count());
    }

    public function test_batched_date_evaluation_generates_requests_for_due_assignments(): void
    {
        $rule = $this->createRule();
        $due = $this->assign($this->createAsset(), $rule, ['last_triggered_date' => now()->subDays(31)]);
        $notDue = $this->assign($this->createAsset(), $rule, ['last_triggered_date' => now()->subDays(2)]);

        (new EvaluatePmRulesJob)->handle();

        $this->assertSame(1, MaintenanceRequest::count());
        $this->assertTrue(MaintenanceRequest::where('asset_id', $due->asset_id)->exists());
        $this->assertFalse(MaintenanceRequest::where('asset_id', $notDue->asset_id)->exists());
    }

    public function test_batched_reading_evaluation_matches_the_per_assignment_verdict(): void
    {
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);
        $rule = $this->createRule([
            'trigger_type' => PmTriggerType::READING,
            'interval_days' => null,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
        ]);

        $over = $this->assign($this->createAsset(), $rule, ['last_triggered_reading' => 5000]);
        $under = $this->assign($this->createAsset(), $rule, ['last_triggered_reading' => 5000]);

        // An older, lower reading on the due asset proves the batch picks the
        // latest by reading_at rather than whatever the database returns first.
        $this->recordReading($over->asset_id, $readingType->id, 5200, now()->subDays(3));
        $this->recordReading($over->asset_id, $readingType->id, 6100, now());
        $this->recordReading($under->asset_id, $readingType->id, 5400, now());

        (new EvaluatePmRulesJob)->handle();

        $this->assertTrue(MaintenanceRequest::where('asset_id', $over->asset_id)->exists());
        $this->assertFalse(MaintenanceRequest::where('asset_id', $under->asset_id)->exists());

        $mr = MaintenanceRequest::where('asset_id', $over->asset_id)->first();
        $this->assertEquals(6100, (float) $mr->trigger_reading_value);
    }

    public function test_batched_evaluation_honours_a_date_suppression(): void
    {
        $rule = $this->createRule();
        $assignment = $this->assign($this->createAsset(), $rule, ['last_triggered_date' => now()->subDays(31)]);

        PmOccurrenceSuppression::create([
            'pm_rule_id' => $rule->id,
            'asset_id' => $assignment->asset_id,
            'maintenance_request_id' => $this->rejectedRequest($assignment->asset_id, $rule)->id,
            'trigger_type' => PmTriggerType::DATE,
            'decision_type' => 'rejected',
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
            'suppressed_until_date' => now()->addYear(),
            'decided_by' => $this->userId,
            'decided_at' => now(),
            'reason' => 'Deferred',
        ]);

        (new EvaluatePmRulesJob)->handle();

        $this->assertSame(0, $this->generatedRequests());
    }

    public function test_batched_evaluation_honours_a_reading_suppression(): void
    {
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h']);
        $rule = $this->createRule([
            'trigger_type' => PmTriggerType::READING,
            'interval_days' => null,
            'interval_reading' => 1000,
            'usage_reading_type_id' => $readingType->id,
        ]);
        $assignment = $this->assign($this->createAsset(), $rule, ['last_triggered_reading' => 5000]);
        $this->recordReading($assignment->asset_id, $readingType->id, 6100, now());

        PmOccurrenceSuppression::create([
            'pm_rule_id' => $rule->id,
            'asset_id' => $assignment->asset_id,
            'maintenance_request_id' => $this->rejectedRequest($assignment->asset_id, $rule)->id,
            'trigger_type' => PmTriggerType::READING,
            'decision_type' => 'rejected',
            'triggered_by_date' => false,
            'triggered_by_reading' => true,
            'suppressed_until_reading' => 7000,
            'decided_by' => $this->userId,
            'decided_at' => now(),
            'reason' => 'Deferred',
        ]);

        (new EvaluatePmRulesJob)->handle();

        $this->assertSame(0, $this->generatedRequests());
    }

    /**
     * A suppression belonging to a different asset on the same rule must not
     * leak across — the batch re-keys suppressions from (rule, asset) onto
     * assignment ids, and that mapping is where a leak would happen.
     */
    public function test_a_suppression_does_not_leak_to_another_asset(): void
    {
        $rule = $this->createRule();
        $suppressed = $this->assign($this->createAsset(), $rule, ['last_triggered_date' => now()->subDays(31)]);
        $other = $this->assign($this->createAsset(), $rule, ['last_triggered_date' => now()->subDays(31)]);

        PmOccurrenceSuppression::create([
            'pm_rule_id' => $rule->id,
            'asset_id' => $suppressed->asset_id,
            'maintenance_request_id' => $this->rejectedRequest($suppressed->asset_id, $rule)->id,
            'trigger_type' => PmTriggerType::DATE,
            'decision_type' => 'rejected',
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
            'suppressed_until_date' => now()->addYear(),
            'decided_by' => $this->userId,
            'decided_at' => now(),
            'reason' => 'Deferred',
        ]);

        (new EvaluatePmRulesJob)->handle();

        $this->assertSame(0, $this->generatedRequests($suppressed->asset_id));
        $this->assertSame(1, $this->generatedRequests($other->asset_id));
    }

    /**
     * A suppression must point at the request whose rejection created it.
     * Rejected is deliberate: a pending one would count as an active chain and
     * block evaluation for a reason other than the suppression under test.
     */
    private function rejectedRequest(int $assetId, PmRule $rule): MaintenanceRequest
    {
        return MaintenanceRequest::create([
            'number' => 'MR-SEED-'.uniqid(),
            'asset_id' => $assetId,
            'status' => MaintenanceRequestStatus::REJECTED,
            'priority' => 'medium',
            'description' => 'Rejected PM',
            'created_by' => $this->userId,
            'is_preventive' => true,
            'pm_rule_id' => $rule->id,
        ]);
    }

    private function generatedRequests(?int $assetId = null): int
    {
        return MaintenanceRequest::query()
            ->where('status', MaintenanceRequestStatus::PENDING_REVIEW)
            ->when($assetId !== null, fn ($q) => $q->where('asset_id', $assetId))
            ->count();
    }

    private function recordReading(int $assetId, int $readingTypeId, float $value, $at): void
    {
        AssetMeterReading::create([
            'asset_id' => $assetId,
            'usage_reading_type_id' => $readingTypeId,
            'reading_value' => $value,
            'reading_at' => $at,
            'source' => 'user',
            'confirmed_by_user_id' => $this->userId,
            'confirmed_at' => now(),
        ]);
    }
}
