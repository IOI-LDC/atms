<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $employees = [
            ['emp_id' => 'EMP-001', 'name' => 'Ahmad Al-Mansouri',   'email' => 'a.mansouri@ldc.ly',  'department' => 'Operations',  'job_title' => 'Operations Manager'],
            ['emp_id' => 'EMP-002', 'name' => 'Khalid Al-Farsi',     'email' => 'k.farsi@ldc.ly',     'department' => 'Maintenance', 'job_title' => 'Senior Maintenance Engineer'],
            ['emp_id' => 'EMP-003', 'name' => 'Nadia Bashir',        'email' => 'n.bashir@ldc.ly',    'department' => 'Maintenance', 'job_title' => 'Maintenance Technician'],
            ['emp_id' => 'EMP-004', 'name' => 'Omar Al-Zawawi',      'email' => 'o.zawawi@ldc.ly',    'department' => 'Field',       'job_title' => 'Field Engineer'],
            ['emp_id' => 'EMP-005', 'name' => 'Fatima Al-Ghadban',   'email' => 'f.ghadban@ldc.ly',   'department' => 'Logistics',   'job_title' => 'Logistics Coordinator'],
            ['emp_id' => 'EMP-006', 'name' => 'Youssef Al-Mukhtar',  'email' => 'y.mukhtar@ldc.ly',   'department' => 'Warehouse',   'job_title' => 'Warehouse Supervisor'],
            ['emp_id' => 'EMP-007', 'name' => 'Rania Al-Shibani',    'email' => 'r.shibani@ldc.ly',   'department' => 'Field',       'job_title' => 'Site Safety Officer'],
            ['emp_id' => 'EMP-008', 'name' => 'Ibrahim Al-Warfalli', 'email' => 'i.warfalli@ldc.ly',  'department' => 'Operations',  'job_title' => 'Operations Technician'],
        ];

        foreach ($employees as $emp) {
            Employee::firstOrCreate(
                ['emp_id' => $emp['emp_id']],
                array_merge($emp, [
                    'sharepoint_item_id' => (string) Str::uuid(),
                    'source_is_active'   => true,
                    'last_synced_at'     => now(),
                ]),
            );
        }
    }
}
