<?php

namespace App\Actions\ApiClients;

use App\Models\ApiClient;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class RevokeApiClient
{
    public function execute(ApiClient $client): ApiClient
    {
        return DB::transaction(function () use ($client) {
            $before = ['revoked_at' => $client->revoked_at];

            $client->update(['revoked_at' => now()]);

            app(AuditLogger::class)->log('api_client_revoked', $client, $before, ['revoked_at' => $client->revoked_at]);

            return $client->fresh();
        });
    }
}
