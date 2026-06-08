<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Asset::class);

        $user = $request->user();
        $query = Asset::query()->with('currentLocation');

        if (! $user->hasRole(RoleCode::ADMINISTRATOR) && ! $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            $query->where('is_active', true);
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $results = $query->cursorPaginate($perPage);

        return AssetResource::collection($results)->toResponse($request);
    }

    public function show(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $asset->load('currentLocation');

        return (new AssetResource($asset))->toResponse($request);
    }

    public function meterReadings(Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return response()->json(['data' => $asset->meterReadings()->orderByDesc('reading_at')->get()]);
    }

    public function locationHistory(Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return response()->json(['data' => $asset->locationHistories()->orderByDesc('effective_at')->get()]);
    }
}
