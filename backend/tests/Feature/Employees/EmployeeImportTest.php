<?php

namespace Tests\Feature\Employees;

use App\Contracts\Employees\EmployeeDirectorySource;
use App\Enums\RoleCode;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Employees\FakeEmployeeDirectorySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    public function test_import_upserts_by_sharepoint_item_id_and_emp_id(): void
    {
        $fakeSource = new FakeEmployeeDirectorySource();
        $fakeSource->setEmployees([
            [
                'sharepoint_item_id' => 'sp-123',
                'emp_id' => 'E1001',
                'name' => 'Alice Smith',
                'email' => 'alice@example.com',
                'department' => 'Maintenance',
            ]
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
            ]
        ]);

        $this->actingAs($admin)->postJson('/api/admin/employees/import')->assertOk();
        
        $this->assertDatabaseCount('employees', 1); // Still 1
        $this->assertDatabaseHas('employees', ['emp_id' => 'E1001', 'name' => 'Alice Smith (Updated)']);
    }

    public function test_import_never_creates_users(): void
    {
        $fakeSource = new FakeEmployeeDirectorySource();
        $fakeSource->setEmployees([
            [
                'sharepoint_item_id' => 'sp-124',
                'emp_id' => 'E1002',
                'name' => 'Bob Jones',
                'email' => 'bob@example.com',
            ]
        ]);
        
        $this->app->instance(EmployeeDirectorySource::class, $fakeSource);

        $admin = $this->createAdmin();
        $initialUserCount = User::count();

        $this->actingAs($admin)->postJson('/api/admin/employees/import')->assertOk();
        
        $this->assertDatabaseCount('employees', 1);
        $this->assertEquals($initialUserCount, User::count()); // User count unchanged
    }
}
