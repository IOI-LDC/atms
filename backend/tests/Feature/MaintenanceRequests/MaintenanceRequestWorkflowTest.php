<?php

namespace Tests\Feature\MaintenanceRequests;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
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
            'erp_asset_code' => 'AST-MR-'.uniqid(),
            'name' => 'Test Asset',
            'is_active' => true,
        ]);
    }

    public function test_requester_creates_corrective_request(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Machine making unusual noise',
            'priority' => 'high',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['id', 'number', 'status']]);
        $this->assertDatabaseHas('maintenance_requests', [
            'asset_id' => $asset->id,
            'type' => 'corrective',
            'status' => 'pending_review',
            'created_by' => $requester->id,
            'priority' => 'high',
        ]);

        $number = $response->json('data.number');
        $this->assertMatchesRegularExpression('/^MR-\d{6}$/', $number);
    }

    public function test_corrective_request_with_optional_unverified_reading(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $readingType = UsageReadingType::create([
            'name' => 'Operating Hours',
            'unit' => 'hours',
        ]);

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Vibration detected',
            'priority' => 'medium',
            'meter_reading' => [
                'usage_reading_type_id' => $readingType->id,
                'reading_value' => 1234.50,
                'reading_at' => now()->toIso8601String(),
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('asset_meter_readings', [
            'asset_id' => $asset->id,
            'reading_value' => 1234.50,
            'confirmed_at' => null,
        ]);
    }

    public function test_requester_sees_own_requests_only(): void
    {
        $requester1 = $this->createUser(RoleCode::REQUESTER);
        $requester2 = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $this->actingAs($requester1)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Request 1',
            'priority' => 'low',
        ]);

        $this->actingAs($requester2)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Request 2',
            'priority' => 'low',
        ]);

        $response = $this->actingAs($requester1)->getJson('/api/maintenance-requests');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_requester_can_cancel_own_pending_corrective_request(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Will cancel this',
            'priority' => 'low',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($requester)->postJson("/api/maintenance-requests/{$mrId}/cancel", [
            'reason' => 'No longer needed',
        ])->assertOk();

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $mrId,
            'status' => 'cancelled',
        ]);
    }

    public function test_requester_cannot_cancel_others_request(): void
    {
        $requester1 = $this->createUser(RoleCode::REQUESTER);
        $requester2 = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester1)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Belongs to requester1',
            'priority' => 'low',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($requester2)->postJson("/api/maintenance-requests/{$mrId}/cancel", [
            'reason' => 'Trying to cancel',
        ])->assertForbidden();
    }

    public function test_manager_can_cancel_any_pending_request(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Manager will cancel',
            'priority' => 'low',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/cancel", [
            'reason' => 'Duplicate',
        ])->assertOk();
    }

    public function test_manager_rejects_pending_request(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Will be rejected',
            'priority' => 'low',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/reject", [
            'reason' => 'Not justified',
        ])->assertOk();

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $mrId,
            'status' => 'rejected',
            'reviewed_by' => $manager->id,
        ]);
    }

    public function test_approval_allowed_only_from_pending_review(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Double approval test',
            'priority' => 'high',
        ]);

        $mrId = $response->json('data.id');

        // First approval succeeds
        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/approve")
            ->assertOk();

        // Second approval fails (already converted)
        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/approve")
            ->assertStatus(409);
    }

    public function test_approval_atomically_creates_work_order_and_sets_converted(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Approve and create WO',
            'priority' => 'high',
        ]);

        $mrId = $response->json('data.id');

        $approveResponse = $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/approve");
        $approveResponse->assertOk();

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $mrId,
            'status' => 'converted',
            'reviewed_by' => $manager->id,
        ]);

        $this->assertDatabaseHas('work_orders', [
            'maintenance_request_id' => $mrId,
            'asset_id' => $asset->id,
            'priority' => 'high',
            'status' => 'open',
        ]);
    }

    public function test_no_approved_status_exists(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Verify no approved status',
            'priority' => 'medium',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/approve")->assertOk();

        $mr = DB::table('maintenance_requests')->find($mrId);
        $this->assertEquals('converted', $mr->status);
        $this->assertNotEquals('approved', $mr->status);
    }

    public function test_mr_number_is_unique_and_atomic(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $r1 = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'First',
            'priority' => 'low',
        ]);

        $r2 = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Second',
            'priority' => 'low',
        ]);

        $this->assertNotEquals($r1->json('data.number'), $r2->json('data.number'));
    }

    public function test_concurrent_approval_creates_one_work_order(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Concurrent test',
            'priority' => 'high',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/approve")->assertOk();

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/approve")
            ->assertStatus(409);

        $this->assertEquals(1, DB::table('work_orders')->where('maintenance_request_id', $mrId)->count());

        $mr = DB::table('maintenance_requests')->find($mrId);
        $this->assertEquals('converted', $mr->status);

        $wo = DB::table('work_orders')->where('maintenance_request_id', $mrId)->first();
        $this->assertNotNull($wo);
        $this->assertEquals($asset->id, $wo->asset_id);
        $this->assertEquals('open', $wo->status);
    }

    public function test_converted_request_cannot_be_cancelled(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Converted cancel test',
            'priority' => 'high',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/approve")->assertOk();

        $this->actingAs($requester)->postJson("/api/maintenance-requests/{$mrId}/cancel", [
            'reason' => 'Too late',
        ])->assertStatus(409);
    }

    public function test_rejected_request_cannot_be_approved(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Reject then approve',
            'priority' => 'low',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/reject", [
            'reason' => 'Invalid',
        ])->assertOk();

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/approve")
            ->assertStatus(409);
    }

    public function test_work_order_inherits_priority_snapshot(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Priority snapshot test',
            'priority' => 'critical',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/approve")->assertOk();

        $this->assertDatabaseHas('work_orders', [
            'maintenance_request_id' => $mrId,
            'priority' => 'critical',
        ]);
    }

    public function test_work_order_has_unique_number(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $r1 = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'First WO number',
            'priority' => 'low',
        ]);

        $r2 = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Second WO number',
            'priority' => 'low',
        ]);

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$r1->json('data.id')}/approve")->assertOk();
        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$r2->json('data.id')}/approve")->assertOk();

        $wo1 = DB::table('work_orders')->where('maintenance_request_id', $r1->json('data.id'))->first();
        $wo2 = DB::table('work_orders')->where('maintenance_request_id', $r2->json('data.id'))->first();

        $this->assertNotEquals($wo1->number, $wo2->number);
        $this->assertMatchesRegularExpression('/^WO-\d{6}$/', $wo1->number);
        $this->assertMatchesRegularExpression('/^WO-\d{6}$/', $wo2->number);
    }

    public function test_viewer_cannot_create_maintenance_request(): void
    {
        $viewer = $this->createUser(RoleCode::VIEWER);
        $asset = $this->createAsset();

        $this->actingAs($viewer)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Viewer attempt',
            'priority' => 'low',
        ])->assertForbidden();
    }

    public function test_technician_can_create_corrective_request(): void
    {
        $tech = $this->createUser(RoleCode::TECHNICIAN);
        $asset = $this->createAsset();

        $this->actingAs($tech)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Tech report',
            'priority' => 'medium',
        ])->assertCreated();
    }

    public function test_requester_cannot_approve(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Self-approve attempt',
            'priority' => 'low',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($requester)->postJson("/api/maintenance-requests/{$mrId}/approve")
            ->assertForbidden();
    }

    public function test_cancellation_requires_reason(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Cancel without reason',
            'priority' => 'low',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($requester)->postJson("/api/maintenance-requests/{$mrId}/cancel")
            ->assertStatus(422);
    }

    public function test_rejection_requires_reason(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $asset = $this->createAsset();

        $response = $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Reject without reason',
            'priority' => 'low',
        ]);

        $mrId = $response->json('data.id');

        $this->actingAs($manager)->postJson("/api/maintenance-requests/{$mrId}/reject")
            ->assertStatus(422);
    }

    public function test_partial_meter_reading_payload_is_rejected(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Partial reading',
            'priority' => 'low',
            'meter_reading' => [
                'reading_value' => 100,
            ],
        ])->assertStatus(422);
    }

    public function test_empty_meter_reading_object_is_rejected(): void
    {
        $requester = $this->createUser(RoleCode::REQUESTER);
        $asset = $this->createAsset();

        $this->actingAs($requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $asset->id,
            'description' => 'Empty reading object',
            'priority' => 'low',
            'meter_reading' => [],
        ])->assertStatus(422);
    }
}
