<?php

namespace App\Services\Employees;

use App\Contracts\Employees\EmployeeDirectorySource;
use App\Data\Employees\ExternalEmployeeData;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CsvEmployeeDirectorySource implements EmployeeDirectorySource
{
    public function __construct(
        private readonly string $filePath,
    ) {}

    public function getEmployees(): Collection
    {
        if (! file_exists($this->filePath)) {
            return collect();
        }

        $handle = fopen($this->filePath, 'r');

        if (! $handle) {
            return collect();
        }

        // Line 1: SharePoint ListSchema metadata — skip.
        fgetcsv($handle, escape: '');

        // Line 2: header row.
        $headers = fgetcsv($handle, escape: '');

        if (! $headers) {
            fclose($handle);

            return collect();
        }

        $employees = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $data = array_combine($headers, $row);

            if (! $data || empty($data['emp_id'])) {
                continue;
            }

            $employees[] = $this->mapRow($data);
        }

        fclose($handle);

        return collect($employees);
    }

    /**
     * @param  array<string, string>  $data
     */
    public function findOrFail(string $empId): ExternalEmployeeData
    {
        $employees = $this->getEmployees();

        $match = $employees->first(fn (ExternalEmployeeData $e) => $e->empId === $empId);

        if (! $match) {
            throw new \DomainException("Employee with emp_id '{$empId}' not found in directory.");
        }

        return $match;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function mapRow(array $data): ExternalEmployeeData
    {
        $firstName = trim($data['first_name'] ?? '');
        $middleName = trim($data['middle_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');

        $name = implode(' ', array_filter([$firstName, $middleName, $lastName]));

        if ($name === '') {
            $name = $data['Title'] ?? '';
        }

        return new ExternalEmployeeData(
            sharepointItemId: $data['ID'] ?? '',
            empId: $data['emp_id'] ?? '',
            name: $name,
            email: $data['company_email'] ?? '',
            department: (! empty($data['department_code'])) ? $data['department_code'] : null,
            jobTitle: (! empty($data['job_title'])) ? $data['job_title'] : null,
            isActive: ($data['status'] ?? '') === 'Active',
            updatedAt: (! empty($data['Modified'])) ? Carbon::parse($data['Modified']) : null,
            rawData: $data,
        );
    }
}
