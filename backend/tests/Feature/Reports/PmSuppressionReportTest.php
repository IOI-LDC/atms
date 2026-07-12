<?php

namespace Tests\Feature\Reports;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\MaintenanceRequest;
use App\Models\PmOccurrenceSuppression;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PmSuppressionReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = $this->createUser(RoleCode::ADMINISTRATOR);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAsset(string $name = 'Asset'): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'ASSET-'.uniqid(),
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function createRule(PmTriggerType $triggerType = PmTriggerType::DATE): PmRule
    {
        return PmRule::create([
            'name' => 'Rule-'.uniqid(),
            'trigger_type' => $triggerType,
            'interval_days' => $triggerType === PmTriggerType::DATE ? 30 : null,
            'interval_reading' => $triggerType === PmTriggerType::READING ? 100 : null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    private function createMaintenanceRequest(Asset $asset, PmRule $rule): MaintenanceRequest
    {
        return MaintenanceRequest::forceCreate([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'pm_rule_id' => $rule->id,
            'status' => MaintenanceRequestStatus::REJECTED,
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
        ]);
    }

    private function createSuppression(array $overrides = []): PmOccurrenceSuppression
    {
        $asset = $overrides['asset'] ?? $this->createAsset();
        $rule = $overrides['rule'] ?? $this->createRule();
        $maintenanceRequest = $overrides['maintenance_request'] ?? $this->createMaintenanceRequest($asset, $rule);

        return PmOccurrenceSuppression::create(array_merge([
            'pm_rule_id' => $rule->id,
            'asset_id' => $asset->id,
            'maintenance_request_id' => $maintenanceRequest->id,
            'trigger_type' => $rule->trigger_type,
            'decision_type' => 'rejected',
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
            'trigger_date' => now()->toDateString(),
            'suppressed_until_date' => now()->addDays(30)->toDateString(),
            'decided_by' => $this->admin->id,
            'decided_at' => now()->subDay(),
            'reason' => 'Deferred by manager',
        ], collect($overrides)->except(['asset', 'rule', 'maintenance_request'])->all()));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/reports/pm-suppression')->assertUnauthorized();
    }

    public function test_returns_date_triggered_audit_context(): void
    {
        $asset = $this->createAsset('Generator');
        $rule = $this->createRule();
        $suppression = $this->createSuppression(['asset' => $asset, 'rule' => $rule]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-suppression')->json();

        $this->assertSame(1, $json['summary']['total_suppressions']);
        $this->assertSame($suppression->id, $json['data'][0]['id']);
        $this->assertSame('rejected', $json['data'][0]['decision_type']);
        $this->assertTrue($json['data'][0]['triggered_by_date']);
        $this->assertFalse($json['data'][0]['triggered_by_reading']);
        $this->assertNotNull($json['data'][0]['suppressed_until_date']);
        $this->assertSame($rule->name, $json['data'][0]['pm_rule']['name']);
        $this->assertSame('Generator', $json['data'][0]['asset']['name']);
        $this->assertSame($this->admin->name, $json['data'][0]['decided_by']['name']);
        $this->assertSame('Deferred by manager', $json['data'][0]['reason']);
    }

    public function test_returns_reading_trigger_context(): void
    {
        $readingType = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        $rule = $this->createRule(PmTriggerType::READING);
        $suppression = $this->createSuppression([
            'rule' => $rule,
            'triggered_by_date' => false,
            'triggered_by_reading' => true,
            'trigger_date' => null,
            'trigger_reading_value' => 500,
            'trigger_reading_type_id' => $readingType->id,
            'suppressed_until_date' => null,
            'suppressed_until_reading' => 600,
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-suppression')->json();
        $item = $json['data'][0];

        $this->assertSame($suppression->id, $item['id']);
        $this->assertTrue($item['triggered_by_reading']);
        $this->assertEquals(500.0, $item['trigger_reading_value']);
        $this->assertEquals(600.0, $item['suppressed_until_reading']);
        $this->assertSame('Hours', $item['trigger_reading_type']['name']);
    }

    public function test_filters_by_rule_asset_and_decision_type(): void
    {
        $targetAsset = $this->createAsset('Target');
        $otherAsset = $this->createAsset('Other');
        $targetRule = $this->createRule();
        $otherRule = $this->createRule();
        $this->createSuppression(['asset' => $targetAsset, 'rule' => $targetRule, 'decision_type' => 'cancelled']);
        $this->createSuppression(['asset' => $otherAsset, 'rule' => $otherRule, 'decision_type' => 'rejected']);

        $url = '/api/reports/pm-suppression?pm_rule_id='.$targetRule->id
            .'&asset_id='.$targetAsset->id.'&decision_type=cancelled';
        $json = $this->actingAs($this->admin)->getJson($url)->json();

        $this->assertSame(1, $json['summary']['total_suppressions']);
        $this->assertSame('cancelled', $json['data'][0]['decision_type']);
    }

    public function test_date_filter_includes_entire_to_date(): void
    {
        $decidedAt = now()->subDays(5)->setTime(14, 0);
        $this->createSuppression(['decided_at' => $decidedAt]);
        $date = $decidedAt->toDateString();

        $json = $this->actingAs($this->admin)
            ->getJson("/api/reports/pm-suppression?from={$date}&to={$date}")
            ->json();

        $this->assertSame(1, $json['summary']['total_suppressions']);
    }

    public function test_cursor_links_preserve_filters_and_traverse_duplicate_timestamps(): void
    {
        $asset = $this->createAsset();
        $decidedAt = now()->subDay();
        foreach (range(1, 5) as $index) {
            $this->createSuppression(['asset' => $asset, 'decided_at' => $decidedAt]);
        }

        $seen = [];
        $url = '/api/reports/pm-suppression?asset_id='.$asset->id.'&per_page=2';
        do {
            $json = $this->actingAs($this->admin)->getJson($url)->json();
            foreach ($json['data'] as $item) {
                $seen[] = $item['id'];
            }
            $url = $json['links']['next'] ?? null;
            if ($url !== null) {
                $this->assertStringContainsString('asset_id='.$asset->id, $url);
                $this->assertStringContainsString('per_page=2', $url);
            }
        } while ($url !== null);

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));
    }

    public function test_invalid_decision_type_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/pm-suppression?decision_type=approved')
            ->assertUnprocessable();
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/pm-suppression')->json();

        $this->assertSame(0, $json['summary']['total_suppressions']);
        $this->assertSame([], $json['data']);
    }
}
