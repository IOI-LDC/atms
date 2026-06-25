<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TokenController extends Controller
{
    public function issue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|string|max:64',
            'client_secret' => 'required|string|max:128',
        ]);

        $client = ApiClient::where('client_id', $validated['client_id'])->first();

        if (! $client || $client->isRevoked() || ! Hash::check($validated['client_secret'], $client->client_secret_hash)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $serviceUser = User::where('email', 'service@atms.internal')->firstOrFail();

        $token = $serviceUser->createToken($client->name, $client->abilities);

        $client->touch('last_used_at');

        return response()->json([
            'token' => $token->plainTextToken,
            'abilities' => $client->abilities,
        ]);
    }
}
