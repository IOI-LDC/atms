<?php

namespace App\Http\Controllers;

use App\Actions\Assets\CreateAsset;
use App\Actions\Assets\UpdateAssetLocation;
use App\Enums\RoleCode;
use App\Http\Resources\AssetResource;
use App\Http\Resources\MaintenanceHistoryResource;
use App\Models\Asset;
use App\Models\Location;
use App\Queries\Assets\AssetIndexQuery;
use App\Queries\MaintenanceHistory\BuildAssetMaintenanceHistory;
use App\Services\Audit\AuditLogger;
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

    public function store(Request $request, CreateAsset $action): JsonResponse
    {
        Gate::authorize('create', Asset::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'operational_status' => ['nullable', 'string', 'in:active,under_maintenance,down,inactive'],
            'current_location_id' => ['nullable', 'exists:locations,id'],
        ]);

        $asset = $action->execute($validated);

        return (new AssetResource($asset->load('currentLocation')))
            ->toResponse($request)
            ->setStatusCode(201);
    }

    public function update(Request $request, Asset $asset, UpdateAssetLocation $locationAction): JsonResponse
    {
        Gate::authorize('update', $asset);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'operational_status' => ['nullable', 'string', 'in:active,under_maintenance,down,inactive'],
            'is_active' => ['nullable', 'boolean'],
            'current_location_id' => ['nullable', 'exists:locations,id'],
            'location_notes' => ['nullable', 'string'],
        ]);

        // --- Handle location change via the existing action ---
        $locationChanged = array_key_exists('current_location_id', $validated)
            && $validated['current_location_id'] !== $asset->current_location_id;

        if ($locationChanged) {
            $location = Location::findOrFail($validated['current_location_id']);

            if (! $location->is_active) {
                return response()->json(['message' => 'Cannot assign an inactive location.'], 422);
            }

            $asset = $locationAction->execute(
                $asset,
                $location,
                null,
                $validated['location_notes'] ?? null,
                auth()->id()
            );
        }

        // --- Update operational fields ---
        $fieldUpdates = array_intersect_key(
            $validated,
            array_flip(['name', 'description', 'category', 'serial_number', 'model', 'manufacturer', 'operational_status', 'is_active'])
        );

        if (! empty($fieldUpdates)) {
            $logger = app(AuditLogger::class);
            $before = $asset->toArray();
            $asset->update($fieldUpdates);
            $after = $asset->fresh()->toArray();
            $logger->log('asset.updated', $asset, $before, $after);
        }

        return (new AssetResource($asset->fresh()->load('currentLocation')))->toResponse($request);
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

