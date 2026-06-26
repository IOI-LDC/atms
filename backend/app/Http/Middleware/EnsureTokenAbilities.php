<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenAbilities
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $request->bearerToken()) {
            return $next($request);
        }

        if (! method_exists($user, 'currentAccessToken') || ! $user->currentAccessToken()) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        $abilities = $token->abilities ?? [];

        Log::debug('Token abilities check', [
            'token_class' => get_class($token),
            'token_id' => $token->id ?? null,
            'token_name' => $token->name ?? null,
            'abilities' => $abilities,
            'has_write' => in_array('write', $abilities),
        ]);

        if (in_array('write', $abilities)) {
            return $next($request);
        }

        $method = strtoupper($request->getMethod());

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return response()->json(['message' => 'This token is read-only and cannot perform mutating requests.'], 403);
        }

        return $next($request);
    }
}
