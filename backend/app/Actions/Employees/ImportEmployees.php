<?php

namespace App\Actions\Employees;

use App\Contracts\Employees\EmployeeDirectorySource;
use App\Models\Employee;

class ImportEmployees
{
    public function __construct(
        private EmployeeDirectorySource $source
    ) {}

    public function execute(): int
    {
        $externalEmployees = $this->source->getEmployees();
        $importedCount = 0;

        foreach ($externalEmployees as $external) {
            Employee::updateOrCreate(
                ['emp_id' => $external->empId],
                [
                    'sharepoint_item_id' => $external->sharepointItemId,
                    'name' => $external->name,
                    'email' => $external->email,
                    'department' => $external->department,
                    'job_title' => $external->jobTitle,
                    'source_is_active' => $external->isActive,
                    'source_updated_at' => $external->updatedAt,
                    'source_raw_data' => $external->rawData,
                    'last_synced_at' => now(),
                ]
            );
            $importedCount++;
        }

        return $importedCount;
    }
}
