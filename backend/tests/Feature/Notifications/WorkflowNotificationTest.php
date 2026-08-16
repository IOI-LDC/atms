<?php

namespace Tests\Feature\Notifications;

use App\Enums\LocationType;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\MaintenanceRequests\MaintenanceRequestApprovedNotification;
use App\Notifications\MaintenanceRequests\MaintenanceRequestRejectedNotification;
use App\Notifications\MaintenanceRequests\MaintenanceRequestSubmittedNotification;
use App\Notifications\WorkOrders\WorkOrderAssignedNotification;
use App\Notifications\WorkOrders\WorkOrderCancelledNotification;
use App\Notifications\WorkOrders\WorkOrderClosedNotification;
use App\Notifications\WorkOrders\WorkOrderCompletedNotification;
use App\Notifications\WorkOrders\WorkOrderStartedNotification;
use App\Support\FrontendUrl;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $manager;

    private User $tech;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->requester = $this->createUser(RoleCode::REQUESTER);
        $this->manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $this->tech = $this->createUser(RoleCode::TECHNICIAN);
        $this->asset = $this->createAsset();
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAsset(): Asset
    {
        // A workshop, not a building: starting a work order requires the asset
        // to already be at a workshop or yard (see StartWorkOrder).
        $location = Location::create(['name' => 'Loc-'.uniqid(), 'type' => LocationType::WORKSHOP->value]);

        return Asset::create([
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Test Pump',
            'is_active' => true,
            'current_location_id' => $location->id,
        ]);
    }

    private function createMr(array $overrides = []): MaintenanceRequest
    {
        return MaintenanceRequest::forceCreate(array_merge([
            'number' => 'MR-'.str_pad((string) (MaintenanceRequest::max('id') + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $this->asset->id,
            'status' => MaintenanceRequestStatus::PENDING_REVIEW,
            'priority' => 'high',
            'description' => 'Test request',
            'created_by' => $this->requester->id,
            'is_preventive' => false,
        ], $overrides));
    }

    private function createApprovedWorkOrder(): WorkOrder
    {
        $mr = $this->createMr(['status' => MaintenanceRequestStatus::CONVERTED]);

        return WorkOrder::forceCreate([
            'number' => 'WO-'.str_pad((string) (WorkOrder::max('id') + 1), 6, '0', STR_PAD_LEFT),
            'asset_id' => $this->asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::OPEN,
            'priority' => 'high',
        ]);
    }

    public function test_mr_submitted_notifies_all_managers(): void
    {
        Notification::fake();

        $this->actingAs($this->requester)->postJson('/api/maintenance-requests/corrective', [
            'asset_id' => $this->asset->id,
            'description' => 'Pump is leaking',
            'priority' => 'high',
        ])->assertCreated();

        Notification::assertSentOnDemand(MaintenanceRequestSubmittedNotification::class,
            function (MaintenanceRequestSubmittedNotification $notification) {
                return in_array($this->manager->email, $notification->managerEmails);
            }
        );
    }

    public function test_mr_approved_notifies_requester_and_assignee(): void
    {
        Notification::fake();

        $mr = $this->createMr();

        $this->actingAs($this->manager)->postJson("/api/maintenance-requests/{$mr->id}/approve", [
            'is_failure' => true,
            'assignee_id' => $this->tech->id,
        ])->assertOk();

        Notification::assertSentOnDemand(MaintenanceRequestApprovedNotification::class,
            function (MaintenanceRequestApprovedNotification $notification) {
                return in_array($this->requester->email, $notification->recipientEmails) &&
                    in_array($this->tech->email, $notification->recipientEmails);
            }
        );
    }

    public function test_mr_rejected_notifies_requester(): void
    {
        Notification::fake();

        $mr = $this->createMr();

        $this->actingAs($this->manager)->postJson("/api/maintenance-requests/{$mr->id}/reject", [
            'reason' => 'Not a real issue',
        ])->assertOk();

        Notification::assertSentOnDemand(MaintenanceRequestRejectedNotification::class,
            function (MaintenanceRequestRejectedNotification $notification) {
                return $notification->requesterEmail === $this->requester->email &&
                    $notification->reason === 'Not a real issue';
            }
        );
    }

    public function test_wo_assigned_notifies_technician(): void
    {
        Notification::fake();

        $wo = $this->createApprovedWorkOrder();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $this->tech->id,
        ])->assertOk();

        Notification::assertSentOnDemand(WorkOrderAssignedNotification::class,
            function (WorkOrderAssignedNotification $notification) {
                return $notification->technicianEmail === $this->tech->email;
            }
        );
    }

    public function test_wo_started_notifies_managers(): void
    {
        Notification::fake();

        $wo = $this->createApprovedWorkOrder();
        $wo->update(['assigned_to_user_id' => $this->tech->id, 'assigned_at' => now()]);

        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/start")->assertOk();

        Notification::assertSentOnDemand(WorkOrderStartedNotification::class,
            function (WorkOrderStartedNotification $notification) {
                return in_array($this->manager->email, $notification->managerEmails) &&
                    $notification->technicianName === $this->tech->name;
            }
        );
    }

    public function test_wo_completed_notifies_managers(): void
    {
        Notification::fake();

        $wo = $this->createApprovedWorkOrder();
        $wo->update([
            'assigned_to_user_id' => $this->tech->id,
            'status' => WorkOrderStatus::IN_PROGRESS,
            'started_at' => now(),
        ]);

        $this->actingAs($this->tech)->postJson("/api/work-orders/{$wo->id}/complete", [
            'completion_notes' => 'Fixed the leak',
        ])->assertOk();
        $this->attachToWorkOrder($wo);

        Notification::assertSentOnDemand(WorkOrderCompletedNotification::class,
            function (WorkOrderCompletedNotification $notification) {
                return in_array($this->manager->email, $notification->managerEmails);
            }
        );
    }

    public function test_wo_closed_notifies_technician_with_manager_cc(): void
    {
        Notification::fake();

        $wo = $this->createApprovedWorkOrder();
        $wo->update([
            'assigned_to_user_id' => $this->tech->id,
            'status' => WorkOrderStatus::COMPLETED,
            'completed_at' => now(),
            'completed_by_user_id' => $this->tech->id,
        ]);
        $this->attachToWorkOrder($wo);

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/close")->assertOk();

        Notification::assertSentOnDemand(WorkOrderClosedNotification::class,
            function (WorkOrderClosedNotification $notification) {
                return $notification->technicianEmail === $this->tech->email &&
                    in_array($this->manager->email, $notification->ccEmails);
            }
        );
    }

    public function test_wo_cancelled_notifies_assigned_technician(): void
    {
        Notification::fake();

        $wo = $this->createApprovedWorkOrder();
        $wo->update(['assigned_to_user_id' => $this->tech->id, 'assigned_at' => now()]);

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'False alarm',
            'asset_status' => 'ready_for_field',
        ])->assertOk();

        Notification::assertSentOnDemand(WorkOrderCancelledNotification::class,
            function (WorkOrderCancelledNotification $notification) {
                return $notification->technicianEmail === $this->tech->email &&
                    $notification->reason === 'False alarm';
            }
        );
    }

    public function test_wo_cancelled_without_assignee_sends_no_notification(): void
    {
        Notification::fake();

        $wo = $this->createApprovedWorkOrder();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/cancel", [
            'reason' => 'Duplicate',
            'asset_status' => 'ready_for_field',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_deep_links_use_the_configured_frontend_url_not_the_api_host(): void
    {
        Notification::fake();
        config()->set('atms.frontend_url', 'https://atms.ldc.com.ly');

        $wo = $this->createApprovedWorkOrder();

        $this->actingAs($this->manager)->postJson("/api/work-orders/{$wo->id}/assign", [
            'user_id' => $this->tech->id,
        ])->assertOk();

        Notification::assertSentOnDemand(WorkOrderAssignedNotification::class,
            function (WorkOrderAssignedNotification $notification) use ($wo) {
                return $notification->actionUrl === "https://atms.ldc.com.ly/work-orders/{$wo->id}";
            }
        );
    }

    public function test_account_links_also_use_the_configured_frontend_url(): void
    {
        config()->set('atms.frontend_url', 'https://atms.ldc.com.ly');

        $this->assertSame('https://atms.ldc.com.ly/activate?token=abc', FrontendUrl::to('/activate?token=abc'));
    }

    public function test_a_trailing_slash_on_the_frontend_url_does_not_double_up(): void
    {
        config()->set('atms.frontend_url', 'https://atms.ldc.com.ly/');

        $this->assertSame('https://atms.ldc.com.ly/work-orders/7', FrontendUrl::to('/work-orders/7'));
    }

    public function test_an_empty_frontend_url_falls_back_to_the_app_url(): void
    {
        // Compose injects FRONTEND_URL as an empty string when the root .env omits
        // it. An empty string is not "unset", so an env() default would not apply
        // and every link would come out relative.
        $original = $_SERVER['FRONTEND_URL'] ?? null;
        $_SERVER['FRONTEND_URL'] = '';

        try {
            $resolved = (require config_path('atms.php'))['frontend_url'];
        } finally {
            if ($original === null) {
                unset($_SERVER['FRONTEND_URL']);
            } else {
                $_SERVER['FRONTEND_URL'] = $original;
            }
        }

        $this->assertNotSame('', $resolved, 'An empty FRONTEND_URL must not resolve to an empty base.');
        $this->assertSame(env('APP_URL'), $resolved);
    }
}
