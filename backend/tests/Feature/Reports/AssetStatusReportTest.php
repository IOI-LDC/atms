<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetStatusReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function user(RoleCode $code): User
    {
        $role = Role::where('code', $code->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function asset(array $attributes = []): Asset
    {
        static $n = 0;
        $n++;

        return Asset::create(array_merge([
            'erp_asset_code' => 'A-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => 'Asset '.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'is_active' => true,
        ], $attributes));
    }

    private function fetch(array $query = [])
    {
        return $this->actingAs($this->user(RoleCode::ADMINISTRATOR))
            ->getJson('/api/reports/asset-status'.($query ? '?'.http_build_query($query) : ''));
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/reports/asset-status')->assertUnauthorized();
    }

    public function test_every_human_role_can_read_the_report(): void
    {
        $this->asset();

        foreach ([
            RoleCode::ADMINISTRATOR,
            RoleCode::MAINTENANCE_MANAGER,
            RoleCode::TECHNICIAN,
            RoleCode::LOGISTICS,
            RoleCode::REQUESTER,
        ] as $role) {
            $this->actingAs($this->user($role))
                ->getJson('/api/reports/asset-status')
                ->assertOk();
        }
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    public function test_row_carries_every_column_ldc_asked_for(): void
    {
        $location = Location::create(['name' => 'Rig A', 'type' => 'rig']);
        $this->asset(['asset_tag' => 'L-RIG-MUD-0001', 'current_location_id' => $location->id]);

        $this->fetch()
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'asset_tag', 'name', 'asset_kind',
                    'operational_status', 'is_booked', 'location', 'assigned_to',
                    'open_work_order_number', 'created_at', 'updated_at',
                ]],
                'summary' => ['total', 'by_status', 'booked'],
            ])
            ->assertJsonPath('data.0.asset_tag', 'L-RIG-MUD-0001')
            ->assertJsonPath('data.0.location', 'Rig A');
    }

    public function test_assigned_to_is_the_technician_on_the_open_work_order(): void
    {
        $technician = $this->user(RoleCode::TECHNICIAN);
        $asset = $this->asset();

        $mr = MaintenanceRequest::create([
            'number' => 'MR-0001',
            'asset_id' => $asset->id,
            'is_preventive' => false,
            'priority' => 'medium',
            'description' => 'Broken',
            'created_by' => $technician->id,
        ]);

        WorkOrder::create([
            'number' => 'WO-0001',
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::OPEN,
            'priority' => 'medium',
            'assigned_to_user_id' => $technician->id,
        ]);

        $this->fetch()
            ->assertOk()
            ->assertJsonPath('data.0.assigned_to', $technician->name)
            ->assertJsonPath('data.0.open_work_order_number', 'WO-0001');
    }

    public function test_assigned_to_is_null_when_no_work_order_is_open(): void
    {
        $this->asset();

        $this->fetch()->assertOk()->assertJsonPath('data.0.assigned_to', null);
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    public function test_filters_by_operational_status_and_summary_matches(): void
    {
        $this->asset(['operational_status' => OperationalStatus::FAILURE->value]);
        $this->asset(['operational_status' => OperationalStatus::READY_FOR_FIELD->value]);
        $this->asset(['operational_status' => OperationalStatus::READY_FOR_FIELD->value]);

        $response = $this->fetch(['operational_status' => 'ready_for_field'])->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('summary.total'));
        $this->assertSame(0, $response->json('summary.by_status.failure'));
    }

    public function test_filters_by_location(): void
    {
        $yard = Location::create(['name' => 'Main Yard', 'type' => 'yard']);
        $rig = Location::create(['name' => 'Rig A', 'type' => 'rig']);

        $this->asset(['current_location_id' => $yard->id]);
        $this->asset(['current_location_id' => $rig->id]);

        $response = $this->fetch(['location_id' => $rig->id])->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Rig A', $response->json('data.0.location'));
    }

    public function test_filters_by_booked(): void
    {
        $admin = $this->user(RoleCode::ADMINISTRATOR);
        $booked = $this->asset();
        $this->asset();

        Booking::create([
            'asset_id' => $booked->id,
            'booked_by' => $admin->id,
            'booked_from' => now()->subDay()->toDateString(),
            'booked_until' => now()->addDays(10)->toDateString(),
            'status' => BookingStatus::ACTIVE,
        ]);

        $response = $this->fetch(['booked' => 1])->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertTrue($response->json('data.0.is_booked'));
        $this->assertSame(1, $response->json('summary.booked'));
    }

    /**
     * The date range is a created/updated filter, NOT point-in-time status —
     * there is no asset status history table to reconstruct the latter.
     */
    public function test_date_range_filters_updated_at_by_default(): void
    {
        $old = $this->asset();
        $old->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();
        $this->asset();

        $response = $this->fetch(['from' => now()->subDays(7)->toDateString()])->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_date_field_can_switch_to_created_at(): void
    {
        $old = $this->asset();
        $old->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();
        $this->asset();

        $response = $this->fetch([
            'date_field' => 'created_at',
            'to' => now()->subDays(7)->toDateString(),
        ])->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_rejects_an_unknown_date_field(): void
    {
        $this->fetch(['date_field' => 'closed_at'])->assertStatus(422);
    }

    // ── Population ───────────────────────────────────────────────────────────

    public function test_withdrawn_and_inactive_assets_are_excluded(): void
    {
        $this->asset();
        $this->asset(['is_active' => false]);
        $this->asset(['maintenance_status' => MaintenanceStatus::WITHDRAWN->value]);

        $response = $this->fetch()->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, $response->json('summary.total'));
    }

    public function test_results_are_cursor_paginated(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->asset();
        }

        $response = $this->fetch(['per_page' => 2])->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertNotNull($response->json('meta.next_cursor'));
    }
}
