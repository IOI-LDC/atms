<?php

namespace Tests\Feature\Employees;

use App\Contracts\Employees\EmployeeDirectorySource;
use App\Enums\RoleCode;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserActivationNotification;
use App\Services\Employees\FakeEmployeeDirectorySource;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmployeeProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function createNonAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::REQUESTER)->first()->id,
            'is_active' => true,
        ]);
    }

    private function injectFakeDirectory(array $employees): FakeEmployeeDirectorySource
    {
        $fake = new FakeEmployeeDirectorySource;
        $fake->setEmployees($employees);
        $this->app->instance(EmployeeDirectorySource::class, $fake);

        return $fake;
    }

    public function test_non_administrator_cannot_provision_users(): void
    {
        $user = $this->createNonAdmin();
        $role = Role::where('code', RoleCode::TECHNICIAN)->first();

        $this->injectFakeDirectory([
            [
                'sharepoint_item_id' => 'sp-001',
                'emp_id' => 'EMP500',
                'name' => 'Charlie Brown',
                'email' => 'charlie@example.com',
            ],
        ]);

        $this->actingAs($user)->postJson('/api/admin/employees/provision-user', [
            'emp_id' => 'EMP500',
            'role_id' => $role->id,
        ])->assertForbidden();
    }

    public function test_administrator_provisions_one_selected_employee_with_one_role(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        $role = Role::where('code', RoleCode::TECHNICIAN)->first();

        $this->injectFakeDirectory([
            [
                'sharepoint_item_id' => 'sp-001',
                'emp_id' => 'EMP500',
                'name' => 'Charlie Brown',
                'email' => 'charlie@example.com',
            ],
        ]);

        $response = $this->actingAs($admin)->postJson('/api/admin/employees/provision-user', [
            'emp_id' => 'EMP500',
            'role_id' => $role->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'emp_id' => 'EMP500',
            'role_id' => $role->id,
            'is_active' => false,
        ]);

        $employee = Employee::where('emp_id', 'EMP500')->first();
        $this->assertNotNull($employee);
        $this->assertDatabaseHas('users', ['employee_id' => $employee->id]);

        $user = User::where('emp_id', 'EMP500')->first();
        Notification::assertSentTo($user, UserActivationNotification::class);
    }

    public function test_duplicate_employee_user_provisioning_returns_409(): void
    {
        $admin = $this->createAdmin();
        $role = Role::where('code', RoleCode::REQUESTER)->first();

        $this->injectFakeDirectory([
            [
                'sharepoint_item_id' => 'sp-002',
                'emp_id' => 'EMP600',
                'name' => 'Diana Prince',
                'email' => 'diana@example.com',
            ],
        ]);

        // First provisioning should succeed
        $this->actingAs($admin)->postJson('/api/admin/employees/provision-user', [
            'emp_id' => 'EMP600',
            'role_id' => $role->id,
        ])->assertOk();

        // Second provisioning should return 409
        $this->actingAs($admin)->postJson('/api/admin/employees/provision-user', [
            'emp_id' => 'EMP600',
            'role_id' => $role->id,
        ])->assertStatus(409);
    }

    public function test_activation_notification_contains_one_time_link_not_password(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        $role = Role::where('code', RoleCode::TECHNICIAN)->first();

        $this->injectFakeDirectory([
            [
                'sharepoint_item_id' => 'sp-003',
                'emp_id' => 'EMP700',
                'name' => 'Eve Adams',
                'email' => 'eve@example.com',
            ],
        ]);

        $this->actingAs($admin)->postJson('/api/admin/employees/provision-user', [
            'emp_id' => 'EMP700',
            'role_id' => $role->id,
        ])->assertOk();

        $user = User::where('emp_id', 'EMP700')->first();

        Notification::assertSentTo($user, UserActivationNotification::class, function ($notification, $channels) {
            $reflection = new \ReflectionClass($notification);
            $property = $reflection->getProperty('activationUrl');
            $url = $property->getValue($notification);

            return str_contains($url, '/activate?token=');
        });
    }
}
