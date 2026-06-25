<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiClientController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', ApiClient::class);

        return response()->json([
            'data' => ApiClient::orderByDesc('created_at')->get()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'client_id' => $c->client_id,
                'abilities' => $c->abilities,
                'last_used_at' => $c->last_used_at?->toIso8601String(),
                'revoked_at' => $c->revoked_at?->toIso8601String(),
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', ApiClient::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'distinct'],
        ]);

        $clientId = Str::random(64);
        $rawSecret = Str::random(64);

        $client = ApiClient::create([
            'name' => $validated['name'],
            'client_id' => $clientId,
            'client_secret_hash' => Hash::make($rawSecret),
            'abilities' => $validated['abilities'] ?? ['read'],
        ]);

        app(AuditLogger::class)->log('api_client_created', $client, [], [
            'name' => $client->name,
            'client_id' => $client->client_id,
            'abilities' => $client->abilities,
        ]);

        return response()->json([
            'data' => [
                'id' => $client->id,
                'name' => $client->name,
                'client_id' => $client->client_id,
                'client_secret' => $rawSecret,
                'abilities' => $client->abilities,
                'created_at' => $client->created_at->toIso8601String(),
            ],
        ], 201);
    }

    public function show(ApiClient $client): JsonResponse
    {
        Gate::authorize('view', $client);

        return response()->json([
            'data' => [
                'id' => $client->id,
                'name' => $client->name,
                'client_id' => $client->client_id,
                'abilities' => $client->abilities,
                'last_used_at' => $client->last_used_at?->toIso8601String(),
                'revoked_at' => $client->revoked_at?->toIso8601String(),
                'created_at' => $client->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroy(ApiClient $client): JsonResponse
    {
        Gate::authorize('delete', $client);

        $client->update(['revoked_at' => now()]);

        app(AuditLogger::class)->log('api_client_revoked', $client, ['revoked_at' => null], ['revoked_at' => $client->revoked_at]);

        return response()->json(null, 204);
    }
}
