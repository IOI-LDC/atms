<?php

namespace Tests\Feature\Notifications;

use App\Actions\WorkOrders\AssignWorkOrder;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Notifications\FakeAccountEmailTransport;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves a rolled-back transition emits no mail.
 *
 * This uses `DatabaseMigrations`, not the `RefreshDatabase` used by sibling tests,
 * and does so deliberately: `RefreshDatabase` wraps each test in a transaction that
 * never commits, so after-commit callbacks never fire and a rollback assertion would
 * pass whether or not the behaviour is correct. Both halves live in one test method
 * because the positive control is what stops the negative one being vacuous.
 */
class NotificationTransactionSafetyTest extends TestCase
{
    use DatabaseMigrations;

    private User $manager;

    private User $tech;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml already selects the sync queue, but this test is meaningless
        // unless the notification runs inline, so it states the requirement locally
        // rather than depending on configuration it does not own.
        config()->set('queue.default', 'sync');

        $this->seed(RoleSeeder::class);

        $this->manager = $this->createUser(RoleCode::MAINTENANCE_MANAGER);
        $this->tech = $this->createUser(RoleCode::TECHNICIAN);

        $location = Location::create(['name' => 'Yard', 'type' => 'building']);
        $this->asset = Asset::create([
            'erp_asset_code' => 'A-TX-1',
            'name' => 'Test Pump',
            'is_active' => true,
            'current_location_id' => $location->id,
        ]);

        FakeAccountEmailTransport::flush();
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createOpenWorkOrder(string $suffix): WorkOrder
    {
        $mr = MaintenanceRequest::forceCreate([
            'number' => 'MR-'.$suffix,
            'asset_id' => $this->asset->id,
            'status' => MaintenanceRequestStatus::CONVERTED,
            'priority' => 'high',
            'description' => 'Test request',
            'created_by' => $this->manager->id,
            'is_preventive' => false,
        ]);

        return WorkOrder::forceCreate([
            'number' => 'WO-'.$suffix,
            'asset_id' => $this->asset->id,
            'maintenance_request_id' => $mr->id,
            'status' => WorkOrderStatus::OPEN,
            'priority' => 'high',
        ]);
    }

    public function test_a_rolled_back_transition_emits_no_mail_but_a_committed_one_does(): void
    {
        $rolledBack = $this->createOpenWorkOrder('000001');

        try {
            DB::transaction(function () use ($rolledBack) {
                app(AssignWorkOrder::class)->execute($rolledBack, $this->tech->id, $this->manager->id);

                throw new RuntimeException('a later step in the same transaction failed');
            });
        } catch (RuntimeException) {
            // Expected — the surrounding transaction rolls back.
        }

        $this->assertNull($rolledBack->fresh()->assigned_to_user_id, 'The assignment itself must not persist.');
        $this->assertSame([], FakeAccountEmailTransport::$sent, 'A rolled-back transition must not emit mail.');

        $committed = $this->createOpenWorkOrder('000002');

        app(AssignWorkOrder::class)->execute($committed, $this->tech->id, $this->manager->id);

        $this->assertSame($this->tech->id, $committed->fresh()->assigned_to_user_id);
        $this->assertCount(1, FakeAccountEmailTransport::$sent, 'A committed transition must emit exactly one mail.');
        $this->assertSame([$this->tech->email], FakeAccountEmailTransport::$sent[0]['to']);
    }
}
