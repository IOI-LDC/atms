<?php

namespace App\Contracts\Erp;

interface ErpSource
{
    /**
     * @param string|null $updatedSince ISO 8601 timestamp
     * @param string|null $cursor Pagination cursor
     * @param int $limit Max records per page
     * @return array{data: \App\Data\Erp\ExternalAssetData[], next_cursor: string|null}
     */
    public function getAssets(?string $updatedSince = null, ?string $cursor = null, int $limit = 100): array;

    /**
     * @param string|null $updatedSince ISO 8601 timestamp
     * @param string|null $cursor Pagination cursor
     * @param int $limit Max records per page
     * @return array{data: \App\Data\Erp\ExternalPartData[], next_cursor: string|null}
     */
    public function getParts(?string $updatedSince = null, ?string $cursor = null, int $limit = 100): array;
}
