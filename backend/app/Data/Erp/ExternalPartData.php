<?php

namespace App\Data\Erp;

class ExternalPartData
{
    public function __construct(
        public string|int $id,
        public string $code,
        public string $name,
        public ?string $description,
        public string $unitOfMeasure,
        public ?string $category,
        public string $status,
        public string $updatedAt,
        public array $rawData
    ) {}
}
