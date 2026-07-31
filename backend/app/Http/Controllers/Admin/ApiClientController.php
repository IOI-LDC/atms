<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ApiClients\CreateApiClient;
use App\Actions\ApiClients\RevokeApiClient;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

    public function store(Request $request, CreateApiClient $action): JsonResponse
    {
        Gate::authorize('create', ApiClient::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'distinct'],
        ]);

        [$client, $rawSecret] = $action->execute($validated['name'], $validated['abilities'] ?? ['read']);

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

    public function destroy(ApiClient $client, RevokeApiClient $action): JsonResponse
    {
        Gate::authorize('delete', $client);

        $action->execute($client);

        return response()->json(null, 204);
    }
}
