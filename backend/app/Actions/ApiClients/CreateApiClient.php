<?php

namespace App\Actions\ApiClients;

use App\Models\ApiClient;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateApiClient
{
    /**
     * @param  array<int, string>  $abilities
     * @return array{0: ApiClient, 1: string}
     */
    public function execute(string $name, array $abilities): array
    {
        return DB::transaction(function () use ($name, $abilities) {
            $clientId = Str::random(64);
            $rawSecret = Str::random(64);

            $client = ApiClient::create([
                'name' => $name,
                'client_id' => $clientId,
                'client_secret_hash' => Hash::make($rawSecret),
                'abilities' => $abilities,
            ]);

            app(AuditLogger::class)->log('api_client_created', $client, [], [
                'name' => $client->name,
                'client_id' => $client->client_id,
                'abilities' => $client->abilities,
            ]);

            return [$client, $rawSecret];
        });
    }
}
