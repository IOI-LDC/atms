<?php

namespace App\Data\Erp;

class ExternalAssetData
{
    public function __construct(
        public string|int $id,
        public string $code,
        public string $name,
        public ?string $description,
        public ?string $category,
        public ?string $serialNumber,
        public ?string $model,
        public ?string $manufacturer,
        public string $status,
        public string $updatedAt,
        public array $rawData
    ) {}
}
