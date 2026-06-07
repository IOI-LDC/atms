<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireServiceApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('mock-erp.service_api_key');
        $providedKey = $request->header('X-Service-API-Key');

        if (empty($expectedKey) || $providedKey !== $expectedKey) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
