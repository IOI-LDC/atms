<?php

namespace App\Services\Erp;

use App\Contracts\Erp\ErpSource;
use App\Data\Erp\ExternalAssetData;
use App\Data\Erp\ExternalPartData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class MockErpHttpSource implements ErpSource
{
    private string $baseUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('mock-erp.url', 'http://mock-erp');
        $this->apiKey = config('mock-erp.api_key', '');
    }

    public function getAssets(?string $updatedSince = null, ?string $cursor = null, int $limit = 100): array
    {
        $response = $this->client()->get("{$this->baseUrl}/api/assets", array_filter([
            'updated_since' => $updatedSince,
            'cursor' => $cursor,
            'limit' => $limit,
        ]));

        $response->throw();

        $data = $response->json();

        $items = array_map(function ($item) {
            return new ExternalAssetData(
                id: $item['id'],
                code: $item['code'],
                name: $item['name'],
                description: $item['description'] ?? null,
                category: $item['category'] ?? null,
                serialNumber: $item['serial_number'] ?? null,
                model: $item['model'] ?? null,
                manufacturer: $item['manufacturer'] ?? null,
                status: $item['status'],
                updatedAt: $item['updated_at'],
                rawData: $item
            );
        }, $data['data'] ?? []);

        return [
            'data' => $items,
            'next_cursor' => $data['next_cursor'] ?? null,
        ];
    }

    public function getParts(?string $updatedSince = null, ?string $cursor = null, int $limit = 100): array
    {
        $response = $this->client()->get("{$this->baseUrl}/api/parts", array_filter([
            'updated_since' => $updatedSince,
            'cursor' => $cursor,
            'limit' => $limit,
        ]));

        $response->throw();

        $data = $response->json();

        $items = array_map(function ($item) {
            return new ExternalPartData(
                id: $item['id'],
                code: $item['code'],
                name: $item['name'],
                description: $item['description'] ?? null,
                unitOfMeasure: $item['unit_of_measure'] ?? 'EA',
                category: $item['category'] ?? null,
                status: $item['status'],
                updatedAt: $item['updated_at'],
                rawData: $item
            );
        }, $data['data'] ?? []);

        return [
            'data' => $items,
            'next_cursor' => $data['next_cursor'] ?? null,
        ];
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'X-Service-API-Key' => $this->apiKey,
        ])->timeout(30)->retry(3, 1000, function (\Exception $e) {
            if ($e instanceof ConnectionException) {
                return true;
            }

            return ($e->getCode() >= 500 && $e->getCode() < 600) || $e->getCode() === 429;
        });
    }
}
