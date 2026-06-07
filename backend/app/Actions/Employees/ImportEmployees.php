<?php

namespace App\Actions\Employees;

use App\Contracts\Employees\EmployeeDirectorySource;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class ImportEmployees
{
    public function __construct(
        private EmployeeDirectorySource $source
    ) {}

    public function execute(): int
    {
        return DB::transaction(function () {
            $externalEmployees = $this->source->getEmployees();
            $importedCount = 0;

            foreach ($externalEmployees as $external) {
                $employee = Employee::firstOrNew(
                    ['sharepoint_item_id' => $external->sharepointItemId]
                );
                
                // Only set emp_id on creation, as it is immutable post-provisioning.
                if (! $employee->exists) {
                    $employee->emp_id = $external->empId;
                }

                $employee->fill([
                    'name' => $external->name,
                    'email' => $external->email,
                    'department' => $external->department,
                    'job_title' => $external->jobTitle,
                    'source_is_active' => $external->isActive,
                    'source_updated_at' => $external->updatedAt,
                    'source_raw_data' => $external->rawData,
                    'last_synced_at' => now(),
                ])->save();

                $importedCount++;
            }

            return $importedCount;
        });
    }
}
