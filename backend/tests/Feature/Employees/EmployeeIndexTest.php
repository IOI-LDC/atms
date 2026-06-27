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

class EmployeeIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        // Disable config-based emp_id whitelist so tests control visibility via query params.
        config(['employees.visible_emp_ids' => null]);
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

    private function injectFakeDirectory(array $employees): void
    {
        $fake = new FakeEmployeeDirectorySource;
        $fake->setEmployees($employees);
        $this->app->instance(EmployeeDirectorySource::class, $fake);
    }

    public function test_non_administrator_cannot_list_employees(): void
    {
        $user = $this->createNonAdmin();

        $this->actingAs($user)->getJson('/api/admin/employees')->assertForbidden();
    }

    public function test_administrator_lists_employees_from_directory(): void
    {
        $admin = $this->createAdmin();

        $this->injectFakeDirectory([
            [
                'sharepoint_item_id' => 'sp-100',
                'emp_id' => 'E1001',
                'name' => 'Alice Smith',
                'email' => 'alice@example.com',
                'department' => 'Maintenance',
                'job_title' => 'Manager',
                'is_active' => true,
            ],
            [
                'sharepoint_item_id' => 'sp-200',
                'emp_id' => 'E1002',
                'name' => 'Bob Jones',
                'email' => 'bob@example.com',
                'department' => 'Engineering',
            ],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/employees');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.emp_id', 'E1001')
            ->assertJsonPath('data.0.name', 'Alice Smith')
            ->assertJsonPath('data.0.email', 'alice@example.com')
            ->assertJsonPath('data.0.department', 'Maintenance')
            ->assertJsonPath('data.0.job_title', 'Manager')
            ->assertJsonPath('data.0.source_is_active', true)
            ->assertJsonPath('data.1.emp_id', 'E1002')
            ->assertJsonPath('data.1.name', 'Bob Jones');
    }

    public function test_employee_list_supports_search(): void
    {
        $admin = $this->createAdmin();

        $this->injectFakeDirectory([
            [
                'sharepoint_item_id' => 'sp-1',
                'emp_id' => 'E3001',
                'name' => 'Alice Smith',
                'email' => 'alice@example.com',
            ],
            [
                'sharepoint_item_id' => 'sp-2',
                'emp_id' => 'E3002',
                'name' => 'Bob Anderson',
                'email' => 'bob@example.com',
            ],
            [
                'sharepoint_item_id' => 'sp-3',
                'emp_id' => 'E3003',
                'name' => 'Charlie Smith',
                'email' => 'charlie@example.com',
            ],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/employees?search=alice');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.emp_id', 'E3001');
    }

    public function test_employee_list_supports_sort_descending(): void
    {
        $admin = $this->createAdmin();

        $this->injectFakeDirectory([
            ['sharepoint_item_id' => 'sp-1', 'emp_id' => 'E2001', 'name' => 'Charlie', 'email' => 'c@e.com'],
            ['sharepoint_item_id' => 'sp-2', 'emp_id' => 'E2002', 'name' => 'Alice', 'email' => 'a@e.com'],
            ['sharepoint_item_id' => 'sp-3', 'emp_id' => 'E2003', 'name' => 'Bob', 'email' => 'b@e.com'],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/employees?sort=name:desc');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Charlie')
            ->assertJsonPath('data.1.name', 'Bob')
            ->assertJsonPath('data.2.name', 'Alice');
    }

    public function test_employee_list_filters_by_emp_ids_comma_separated(): void
    {
        $admin = $this->createAdmin();

        $this->injectFakeDirectory([
            ['sharepoint_item_id' => 'sp-1', 'emp_id' => '45', 'name' => 'Alice', 'email' => 'a@e.com'],
            ['sharepoint_item_id' => 'sp-2', 'emp_id' => '6', 'name' => 'Bob', 'email' => 'b@e.com'],
            ['sharepoint_item_id' => 'sp-3', 'emp_id' => '18', 'name' => 'Charlie', 'email' => 'c@e.com'],
            ['sharepoint_item_id' => 'sp-4', 'emp_id' => '99', 'name' => 'Excluded', 'email' => 'x@e.com'],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/employees?emp_ids=45,6,18');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.emp_id', '45')
            ->assertJsonPath('data.1.emp_id', '6')
            ->assertJsonPath('data.2.emp_id', '18');
    }

    public function test_employee_list_filters_by_emp_ids_array_parameter(): void
    {
        $admin = $this->createAdmin();

        $this->injectFakeDirectory([
            ['sharepoint_item_id' => 'sp-1', 'emp_id' => '45', 'name' => 'Alice', 'email' => 'a@e.com'],
            ['sharepoint_item_id' => 'sp-2', 'emp_id' => '6', 'name' => 'Bob', 'email' => 'b@e.com'],
            ['sharepoint_item_id' => 'sp-3', 'emp_id' => '18', 'name' => 'Charlie', 'email' => 'c@e.com'],
            ['sharepoint_item_id' => 'sp-4', 'emp_id' => '99', 'name' => 'Excluded', 'email' => 'x@e.com'],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/employees?emp_ids[]=45&emp_ids[]=6');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_employee_list_exposes_emp_id_for_provisioning(): void
    {
        $admin = $this->createAdmin();

        $this->injectFakeDirectory([
            [
                'sharepoint_item_id' => 'sp-1',
                'emp_id' => '999',
                'name' => 'Rawand Hawez',
                'email' => 'developer@ldc.com.ly',
            ],
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/employees');

        $response->assertOk()
            ->assertJsonPath('data.0.emp_id', '999')
            ->assertJsonPath('data.0.name', 'Rawand Hawez');
    }
}
