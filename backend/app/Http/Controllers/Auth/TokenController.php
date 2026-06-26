<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TokenController extends Controller
{
    /**
     * Exchange API client credentials for a Sanctum access token.
     *
     * Machine-to-machine endpoint: there is no authenticated user at the gate,
     * so a user-based Gate::authorize() does not apply. The client_id/client_secret
     * verification below IS the authorization boundary. Every successful issuance
     * is recorded in the audit log.
     */
    public function issue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|string|max:64',
            'client_secret' => 'required|string|max:128',
        ]);

        $client = ApiClient::where('client_id', $validated['client_id'])->first();

        if (! $client || $client->isRevoked() || ! Hash::check($validated['client_secret'], $client->client_secret_hash)) {
            app(AuditLogger::class)->log('api_token_issuance_failed', $client, [], [], [
                'client_id' => $validated['client_id'],
                'reason' => $client ? 'invalid_secret_or_revoked' : 'unknown_client',
            ]);

            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $serviceUser = User::where('email', 'service@atms.internal')->firstOrFail();

        $token = $serviceUser->createToken($client->name, $client->abilities);

        $client->touch('last_used_at');

        app(AuditLogger::class)->log('api_token_issued', $client, [], [], [
            'issued_for_user_id' => $serviceUser->id,
            'abilities' => $client->abilities,
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'abilities' => $client->abilities,
        ]);
    }
}
