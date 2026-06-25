<?php

namespace App\Services\Erp;

use App\Contracts\Erp\ErpSource;
use App\Data\Erp\ExternalPartData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LdcErpHttpSource implements ErpSource
{
    public function getParts(?string $updatedSince = null, ?string $cursor = null, int $limit = 100): array
    {
        $endpoint = config('erp.api.parts_endpoint');

        if (empty($endpoint)) {
            Log::warning('LDC_ERP_PARTS_API is not configured; skipping parts sync.');

            return ['data' => [], 'next_cursor' => null];
        }

        $token = $this->acquireToken();
        $baseUrl = config('erp.api.base_url');

        $response = $this->client($token)->get("{$baseUrl}/{$endpoint}", array_filter([
            'updated_since' => $updatedSince,
            'cursor' => $cursor,
            'limit' => $limit,
        ]));

        if ($response->status() === 401) {
            Cache::forget('erp_access_token');
            $token = $this->acquireToken();
            $response = $this->client($token)->get("{$baseUrl}/{$endpoint}", array_filter([
                'updated_since' => $updatedSince,
                'cursor' => $cursor,
                'limit' => $limit,
            ]));
        }

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
                rawData: $item,
            );
        }, $data['data'] ?? []);

        return [
            'data' => $items,
            'next_cursor' => $data['next_cursor'] ?? null,
        ];
    }

    private function acquireToken(): string
    {
        $cached = Cache::get('erp_access_token');
        if ($cached) {
            return $cached;
        }

        $response = Http::asForm()->post(config('erp.oauth.token_url'), [
            'grant_type' => 'client_credentials',
            'client_id' => config('erp.oauth.client_id'),
            'client_secret' => config('erp.oauth.client_secret'),
            'scope' => config('erp.oauth.scope'),
        ]);

        $response->throw();

        $tokenData = $response->json();
        $token = $tokenData['access_token'];
        $ttl = max(60, ($tokenData['expires_in'] ?? 3600) - 60);

        Cache::put('erp_access_token', $token, $ttl);

        return $token;
    }

    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->timeout(config('erp.api.timeout', 30))
            ->retry(3, 100, function (\Exception $e) {
                if ($e instanceof ConnectionException) {
                    return true;
                }

                return ($e->getCode() >= 500 && $e->getCode() < 600) || $e->getCode() === 429;
            });
    }
}
