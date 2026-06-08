<?php

namespace App\Contracts\Employees;

use App\Data\Employees\ExternalEmployeeData;
use Illuminate\Support\Collection;

interface EmployeeDirectorySource
{
    /**
     * @return Collection<int, ExternalEmployeeData>
     */
    public function getEmployees(): Collection;
}
