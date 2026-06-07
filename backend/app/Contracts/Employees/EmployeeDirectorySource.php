<?php

namespace App\Contracts\Employees;

use Illuminate\Support\Collection;

interface EmployeeDirectorySource
{
    /**
     * @return Collection<int, \App\Data\Employees\ExternalEmployeeData>
     */
    public function getEmployees(): Collection;
}
