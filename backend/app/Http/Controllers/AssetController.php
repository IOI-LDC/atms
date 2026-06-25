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
use App\Services\AssetTagService;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
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
            'erp_asset_code' => ['required', 'string', 'max:255', 'unique:assets,erp_asset_code'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'operational_status' => ['nullable', 'string', 'in:active,under_maintenance,down,inactive'],
            'maintenance_status' => ['nullable', 'string', 'in:Active,Inactive'],
            'maintenance_sub_status' => ['nullable', 'string', 'in:Installed,Ready,LIH,DBR,Disposed,Scrapped,Other'],
            'asset_kind' => ['nullable', 'string', 'in:asset,package,component'],
            'current_location_id' => ['nullable', 'exists:locations,id'],
        ]);

        // Only Admin/Manager may set maintenance lifecycle fields
        $user = $request->user();
        $lifecycleFields = ['maintenance_status', 'maintenance_sub_status', 'asset_kind'];
        $hasLifecycleFields = ! empty(array_intersect_key($validated, array_flip($lifecycleFields)));

        if ($hasLifecycleFields && ! $user->hasRole(RoleCode::ADMINISTRATOR) && ! $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return response()->json([
                'message' => 'Only administrators and maintenance managers can change lifecycle fields.',
            ], 403);
        }

        $asset = $action->execute($validated);

        return (new AssetResource($asset->load('currentLocation')))
            ->toResponse($request)
            ->setStatusCode(201);
    }

    public function update(Request $request, Asset $asset, UpdateAssetLocation $locationAction): JsonResponse
    {
        Gate::authorize('update', $asset);

        // Tag immutability guard
        if ($request->has('asset_tag') && $asset->asset_tag !== null && $request->asset_tag !== $asset->asset_tag) {
            if (empty($request->asset_tag)) {
                return response()->json([
                    'errors' => ['asset_tag' => ['Cannot clear an existing asset tag.']],
                ], 422);
            }

            if (! $request->user()->hasRole(RoleCode::ADMINISTRATOR) || empty($request->asset_tag_override_reason)) {
                return response()->json([
                    'errors' => ['asset_tag' => ['Asset tag is immutable after creation.']],
                ], 422);
            }
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'fa_subclass_code' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'operational_status' => ['nullable', 'string', 'in:active,under_maintenance,down,inactive'],
            'maintenance_status' => ['nullable', 'string', 'in:Active,Inactive'],
            'maintenance_sub_status' => ['nullable', 'string', 'in:Installed,Ready,LIH,DBR,Disposed,Scrapped,Other'],
            'asset_kind' => ['nullable', 'string', 'in:asset,package,component'],
            'is_active' => ['nullable', 'boolean'],
            'asset_tag' => ['nullable', 'string', 'max:15'],
            'asset_tag_override_reason' => ['nullable', 'string'],
            'current_location_id' => ['nullable', 'exists:locations,id'],
            'location_notes' => ['nullable', 'string'],
        ]);

        // Only Admin/Manager may change maintenance lifecycle fields
        $user = $request->user();
        $lifecycleFields = ['maintenance_status', 'maintenance_sub_status', 'asset_kind'];
        $hasLifecycleFields = ! empty(array_intersect_key($validated, array_flip($lifecycleFields)));

        if ($hasLifecycleFields && ! $user->hasRole(RoleCode::ADMINISTRATOR) && ! $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return response()->json([
                'message' => 'Only administrators and maintenance managers can change lifecycle fields.',
            ], 403);
        }

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
            array_flip(['name', 'description', 'category', 'fa_subclass_code', 'serial_number', 'model', 'manufacturer', 'operational_status', 'is_active', 'asset_tag', 'asset_tag_override_reason', 'maintenance_status', 'maintenance_sub_status', 'asset_kind'])
        );

        if (array_key_exists('asset_tag', $fieldUpdates) && $fieldUpdates['asset_tag'] !== null) {
            $fieldUpdates['asset_tag_generated_at'] = $asset->asset_tag_generated_at ?? now();
        }

        if (! empty($fieldUpdates)) {
            $logger = app(AuditLogger::class);
            $before = $asset->toArray();

            try {
                $asset->update($fieldUpdates);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'unique constraint') || str_contains($e->getMessage(), '23505')) {
                    return response()->json([
                        'errors' => ['asset_tag' => ['The generated asset tag is already in use.']],
                    ], 409);
                }

                throw $e;
            }

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

    public function suggestTag(Asset $asset, AssetTagService $tagService): JsonResponse
    {
        Gate::authorize('update', $asset);

        $tag = $tagService->generateTag($asset);

        return response()->json([
            'asset_tag' => $tag,
            'collision' => $tag === null,
            'generated_at' => $tag ? now()->toIso8601String() : null,
        ]);
    }

    public function byTag(Request $request): JsonResponse
    {
        $request->validate(['tag' => ['required', 'string', 'max:15']]);

        $asset = Asset::where('asset_tag', $request->query('tag'))->firstOrFail();

        return (new AssetResource($asset->load('currentLocation')))->toResponse($request);
    }
}
