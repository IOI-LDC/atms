<?php

namespace Tests\Feature\Reports;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\PmTriggerType;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverduePmReportTest extends TestCase
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

    private function createAsset(array $overrides = []): Asset
    {
        $location = Location::create(['name' => 'Loc-'.uniqid(), 'type' => 'building']);

        return Asset::create(array_merge([
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ], $overrides));
    }

    private function createOverduePmMr(Asset $asset, string $triggerDate, array $overrides = []): MaintenanceRequest
    {
        return MaintenanceRequest::forceCreate(array_merge([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED,
            'priority' => 'high',
            'description' => 'Overdue PM',
            'created_by' => $this->admin->id,
            'is_preventive' => true,
            'triggered_by_date' => true,
            'triggered_by_reading' => false,
            'trigger_date' => $triggerDate,
            'created_at' => now()->subDays(20),
        ], $overrides));
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/overdue-pm')->assertUnauthorized();
    }

    public function test_includes_past_due_pm_with_open_chain(): void
    {
        $asset = $this->createAsset();
        // trigger_date -10d, no WO => overdue with open chain.
        $mr = $this->createOverduePmMr($asset, now()->subDays(10)->toDateString());

        $json = $this->actingAs($this->admin)->getJson('/api/reports/overdue-pm')->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertSame($mr->id, $json['data'][0]['id']);
        $this->assertSame(10, $json['data'][0]['days_overdue']);
        $this->assertSame('8-30', $json['data'][0]['bucket']);
    }

    public function test_excludes_closed_wo(): void
    {
        $asset = $this->createAsset();
        $mr = $this->createOverduePmMr($asset, now()->subDays(10)->toDateString());
        WorkOrder::forceCreate([
            'number' => 'WO-'.$mr->number, 'asset_id' => $asset->id, 'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::CLOSED, 'priority' => 'high',
            'closed_at' => now()->subDay(), 'closed_by_user_id' => $this->admin->id,
            'created_at' => now()->subDays(5),
        ]);

        // Control: an open-chain overdue PM.
        $this->createOverduePmMr($this->createAsset(), now()->subDays(10)->toDateString());

        $json = $this->actingAs($this->admin)->getJson('/api/reports/overdue-pm')->json();

        $this->assertSame(1, $json['summary']['total']);
    }

    public function test_excludes_rejected_and_cancelled_mr(): void
    {
        $asset = $this->createAsset();
        $this->createOverduePmMr($asset, now()->subDays(10)->toDateString(), [
            'status' => MaintenanceRequestStatus::REJECTED,
        ]);
        $this->createOverduePmMr($asset, now()->subDays(10)->toDateString(), [
            'status' => MaintenanceRequestStatus::CANCELLED,
        ]);

        // Control.
        $this->createOverduePmMr($this->createAsset(), now()->subDays(10)->toDateString());

        $json = $this->actingAs($this->admin)->getJson('/api/reports/overdue-pm')->json();

        $this->assertSame(1, $json['summary']['total']);
    }

    public function test_excludes_non_pm_and_reading_triggered(): void
    {
        $asset = $this->createAsset();
        // Corrective (is_preventive=false) — excluded.
        $this->createOverduePmMr($asset, now()->subDays(10)->toDateString(), [
            'is_preventive' => false, 'triggered_by_date' => true,
        ]);
        // Reading-triggered PM — excluded.
        $this->createOverduePmMr($asset, now()->subDays(10)->toDateString(), [
            'is_preventive' => true, 'triggered_by_date' => false, 'triggered_by_reading' => true,
        ]);

        // Control: date-triggered PM, open chain.
        $this->createOverduePmMr($this->createAsset(), now()->subDays(10)->toDateString());

        $json = $this->actingAs($this->admin)->getJson('/api/reports/overdue-pm')->json();

        $this->assertSame(1, $json['summary']['total']);
    }

    public function test_bucket_filter_returns_only_that_bucket(): void
    {
        $asset = $this->createAsset();
        $this->createOverduePmMr($asset, now()->subDays(10)->toDateString()); // 8-30
        $this->createOverduePmMr($asset, now()->subDays(40)->toDateString()); // 31-90

        $json = $this->actingAs($this->admin)->getJson('/api/reports/overdue-pm?bucket=31-90')->json();

        $this->assertCount(1, $json['data']);
        $this->assertSame('31-90', $json['data'][0]['bucket']);
    }

    public function test_summary_is_facet_context(): void
    {
        $asset = $this->createAsset();
        $this->createOverduePmMr($asset, now()->subDays(5)->toDateString());   // 0-7
        $this->createOverduePmMr($asset, now()->subDays(10)->toDateString());  // 8-30
        $this->createOverduePmMr($asset, now()->subDays(40)->toDateString());  // 31-90
        $this->createOverduePmMr($asset, now()->subDays(100)->toDateString()); // 91+

        // Filter rows to 31-90, but summary reflects the full scoped set.
        $json = $this->actingAs($this->admin)->getJson('/api/reports/overdue-pm?bucket=31-90')->json();

        $this->assertSame(4, $json['summary']['total']);
        $this->assertSame(
            ['0-7' => 1, '8-30' => 1, '31-90' => 1, '91+' => 1],
            $json['summary']['by_bucket']
        );
        $this->assertCount(1, $json['data']);
    }

    public function test_paginated_shape_has_data_links_meta(): void
    {
        $this->createOverduePmMr($this->createAsset(), now()->subDays(10)->toDateString());

        $this->actingAs($this->admin)->getJson('/api/reports/overdue-pm')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
                'summary' => ['total', 'by_bucket'],
            ]);
    }

    public function test_multi_page_traversal_with_duplicate_trigger_dates(): void
    {
        $asset = $this->createAsset();
        $triggerDate = now()->subDays(10)->toDateString();
        foreach (range(1, 5) as $i) {
            $this->createOverduePmMr($asset, $triggerDate);
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $url = '/api/reports/overdue-pm?per_page=2';
            if ($cursor !== null) {
                $url .= '&cursor='.urlencode($cursor);
            }
            $json = $this->actingAs($this->admin)->getJson($url)->json();
            foreach ($json['data'] as $item) {
                $seen[] = $item['id'];
            }
            $cursor = $json['meta']['next_cursor'] ?? null;
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen), 'Cursor traversal must not skip or repeat rows.');
    }

    public function test_age_is_positive_not_negative(): void
    {
        $this->createOverduePmMr($this->createAsset(), now()->subDays(10)->toDateString());

        $json = $this->actingAs($this->admin)->getJson('/api/reports/overdue-pm')->json();

        $this->assertSame(10, $json['data'][0]['days_overdue']);
        $this->assertSame('8-30', $json['data'][0]['bucket']);
    }

    public function test_logistics_cannot_see_pm_trigger_fields(): void
    {
        $logistics = $this->createUser(RoleCode::LOGISTICS);
        $admin = $this->createUser(RoleCode::ADMINISTRATOR);
        $this->createOverduePmMr($this->createAsset(), now()->subDays(10)->toDateString());

        $logisticsItem = $this->actingAs($logistics)->getJson('/api/reports/overdue-pm')->json('data.0');
        $adminItem = $this->actingAs($admin)->getJson('/api/reports/overdue-pm')->json('data.0');

        $gated = [
            'trigger_date', 'triggered_by_date', 'triggered_by_reading', 'trigger_reading_value',
            'is_preventive', 'work_order', 'created_by', 'reviewed_by', 'has_attachments',
        ];

        foreach ($gated as $field) {
            $this->assertArrayNotHasKey($field, $logisticsItem, "Logistics should not see {$field}.");
        }
        $this->assertArrayHasKey('created_at', $logisticsItem);

        foreach ($gated as $field) {
            $this->assertArrayHasKey($field, $adminItem, "Admin should see {$field}.");
        }
    }

    public function test_location_and_priority_filters(): void
    {
        $locA = Location::create(['name' => 'Loc-A', 'type' => 'building']);
        $locB = Location::create(['name' => 'Loc-B', 'type' => 'building']);
        $assetA = $this->createAsset(['current_location_id' => $locA->id]);
        $assetB = $this->createAsset(['current_location_id' => $locB->id]);
        $this->createOverduePmMr($assetA, now()->subDays(10)->toDateString(), ['priority' => 'high']);
        $this->createOverduePmMr($assetB, now()->subDays(10)->toDateString(), ['priority' => 'low']);

        $byLocation = $this->actingAs($this->admin)
            ->getJson('/api/reports/overdue-pm?location_id='.$locA->id)->json();
        $this->assertSame(1, $byLocation['summary']['total']);

        $byPriority = $this->actingAs($this->admin)
            ->getJson('/api/reports/overdue-pm?priority=low')->json();
        $this->assertSame(1, $byPriority['summary']['total']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/overdue-pm')->json();

        $this->assertSame(0, $json['summary']['total']);
        $this->assertSame(
            ['0-7' => 0, '8-30' => 0, '31-90' => 0, '91+' => 0],
            $json['summary']['by_bucket']
        );
        $this->assertSame([], $json['data']);
    }

    public function test_pm_rule_filter_applies(): void
    {
        $ruleA = PmRule::create([
            'name' => 'Rule-A',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $ruleB = PmRule::create([
            'name' => 'Rule-B',
            'trigger_type' => PmTriggerType::DATE,
            'interval_days' => 30,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $assetA = $this->createAsset();
        $assetB = $this->createAsset();
        $this->createOverduePmMr($assetA, now()->subDays(10)->toDateString(), ['pm_rule_id' => $ruleA->id]);
        $this->createOverduePmMr($assetB, now()->subDays(10)->toDateString(), ['pm_rule_id' => $ruleB->id]);

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/overdue-pm?pm_rule_id='.$ruleA->id)->json();

        $this->assertSame(1, $json['summary']['total']);
        $this->assertCount(1, $json['data']);
    }

    public function test_invalid_priority_returns_422(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/overdue-pm?priority=hig')
            ->assertStatus(422);
    }

    public function test_pagination_links_preserve_filters(): void
    {
        $locA = Location::create(['name' => 'Loc-A', 'type' => 'building']);
        $assetA = $this->createAsset(['current_location_id' => $locA->id]);
        // Create 3 overdue PMs to trigger pagination (per_page=2).
        foreach (range(1, 3) as $i) {
            $this->createOverduePmMr($assetA, now()->subDays(10)->toDateString(), ['priority' => 'high']);
        }

        $json = $this->actingAs($this->admin)
            ->getJson('/api/reports/overdue-pm?per_page=2&location_id='.$locA->id.'&priority=high')
            ->json();

        $this->assertNotNull($json['links']['next']);
        $this->assertStringContainsString('location_id='.$locA->id, $json['links']['next']);
        $this->assertStringContainsString('priority=high', $json['links']['next']);
        $this->assertStringContainsString('per_page=2', $json['links']['next']);
    }
}
