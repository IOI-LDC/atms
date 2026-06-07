<?php

namespace App\Data\Employees;

class ExternalEmployeeData
{
    public function __construct(
        public string $sharepointItemId,
        public string $empId,
        public string $name,
        public string $email,
        public ?string $department,
        public ?string $jobTitle,
        public bool $isActive,
        public ?\DateTimeInterface $updatedAt,
        public array $rawData
    ) {}
}
