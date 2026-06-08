<?php

namespace App\Services\Employees;

use App\Contracts\Employees\EmployeeDirectorySource;
use App\Data\Employees\ExternalEmployeeData;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FakeEmployeeDirectorySource implements EmployeeDirectorySource
{
    private array $employees = [];

    public function setEmployees(array $employees): void
    {
        $this->employees = $employees;
    }

    public function getEmployees(): Collection
    {
        return collect($this->employees)->map(function ($data) {
            return new ExternalEmployeeData(
                sharepointItemId: $data['sharepoint_item_id'],
                empId: $data['emp_id'],
                name: $data['name'],
                email: $data['email'],
                department: $data['department'] ?? null,
                jobTitle: $data['job_title'] ?? null,
                isActive: $data['is_active'] ?? true,
                updatedAt: isset($data['updated_at']) ? Carbon::parse($data['updated_at']) : now(),
                rawData: $data['raw_data'] ?? []
            );
        });
    }
}
