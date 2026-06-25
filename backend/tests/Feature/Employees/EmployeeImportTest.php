<?php

namespace Tests\Feature\Employees;

use App\Contracts\Employees\EmployeeDirectorySource;
use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use App\Services\Employees\FakeEmployeeDirectorySource;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
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

    public function test_non_administrator_cannot_import_employees(): void
    {
        $user = $this->createNonAdmin();

        $this->actingAs($user)->postJson('/api/admin/employees/import')
            ->assertForbidden();
    }

    public function test_import_upserts_by_sharepoint_item_id_and_emp_id(): void
    {
        $fakeSource = new FakeEmployeeDirectorySource;
        $fakeSource->setEmployees([
            [
                'sharepoint_item_id' => 'sp-123',
                'emp_id' => 'E1001',
                'name' => 'Alice Smith',
                'email' => 'alice@example.com',
                'department' => 'Maintenance',
            ],
        ]);

        $this->app->instance(EmployeeDirectorySource::class, $fakeSource);

        $admin = $this->createAdmin();

        $this->actingAs($admin)->postJson('/api/admin/employees/import')->assertOk();

        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseHas('employees', ['emp_id' => 'E1001', 'name' => 'Alice Smith']);

        // Upsert test
        $fakeSource->setEmployees([
            [
                'sharepoint_item_id' => 'sp-123', // Same ID
                'emp_id' => 'E1001', // Same Emp ID
                'name' => 'Alice Smith (Updated)',
                'email' => 'alice@example.com',
                'department' => 'Maintenance',
            ],
        ]);

        $this->actingAs($admin)->postJson('/api/admin/employees/import')->assertOk();

        $this->assertDatabaseCount('employees', 1); // Still 1
        $this->assertDatabaseHas('employees', ['emp_id' => 'E1001', 'name' => 'Alice Smith (Updated)']);
    }

    public function test_import_never_creates_users(): void
    {
        $fakeSource = new FakeEmployeeDirectorySource;
        $fakeSource->setEmployees([
            [
                'sharepoint_item_id' => 'sp-124',
                'emp_id' => 'E1002',
                'name' => 'Bob Jones',
                'email' => 'bob@example.com',
            ],
        ]);

        $this->app->instance(EmployeeDirectorySource::class, $fakeSource);

        $admin = $this->createAdmin();
        $initialUserCount = User::count();

        $this->actingAs($admin)->postJson('/api/admin/employees/import')->assertOk();

        $this->assertDatabaseCount('employees', 1);
        $this->assertEquals($initialUserCount, User::count());
    }

    public function test_import_reconciles_by_emp_id_when_sharepoint_id_changes(): void
    {
        $fakeSource = new FakeEmployeeDirectorySource;
        $fakeSource->setEmployees([
            [
                'sharepoint_item_id' => 'sp-old',
                'emp_id' => 'E2001',
                'name' => 'Original',
                'email' => 'orig@example.com',
            ],
        ]);

        $this->app->instance(EmployeeDirectorySource::class, $fakeSource);

        $admin = $this->createAdmin();
        $this->actingAs($admin)->postJson('/api/admin/employees/import')->assertOk();
        $this->assertDatabaseCount('employees', 1);

        $fakeSource->setEmployees([
            [
                'sharepoint_item_id' => 'sp-new',
                'emp_id' => 'E2001',
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ],
        ]);

        $this->actingAs($admin)->postJson('/api/admin/employees/import')->assertOk();

        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseHas('employees', [
            'emp_id' => 'E2001',
            'name' => 'Updated Name',
            'sharepoint_item_id' => 'sp-new',
        ]);
    }
}
