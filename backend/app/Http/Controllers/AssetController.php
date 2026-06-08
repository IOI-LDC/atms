<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Http\Resources\AssetResource;
use App\Http\Resources\MaintenanceHistoryResource;
use App\Models\Asset;
use App\Queries\Assets\AssetIndexQuery;
use App\Queries\MaintenanceHistory\BuildAssetMaintenanceHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Asset::class);

        $results = app(AssetIndexQuery::class)->build($request);

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

    public function maintenanceHistory(Request $request, Asset $asset)
    {
        Gate::authorize('view', $asset);

        $user = $request->user();
        if ($user->hasRole(RoleCode::LOGISTICS)) {
            abort(403);
        }

        $results = app(BuildAssetMaintenanceHistory::class)->build($asset, $request);

        return MaintenanceHistoryResource::collection($results)->toResponse($request);
    }
}
