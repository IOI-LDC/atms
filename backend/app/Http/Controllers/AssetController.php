<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AssetController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Asset::class);

        $user = auth()->user();
        $query = Asset::query();

        if (! $user->hasRole(RoleCode::ADMINISTRATOR) && ! $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return response()->json(['data' => $asset]);
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
