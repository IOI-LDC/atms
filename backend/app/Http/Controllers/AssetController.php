<?php

namespace App\Http\Controllers;

use App\Actions\Assets\CreateAsset;
use App\Actions\Assets\UpdateAssetFields;
use App\Actions\Assets\UpdateAssetLocation;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Http\Resources\AssetLocationHistoryResource;
use App\Http\Resources\AssetMeterReadingResource;
use App\Http\Resources\AssetResource;
use App\Http\Resources\MaintenanceHistoryResource;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MasterDataItem;
use App\Queries\Assets\AssetIndexQuery;
use App\Queries\MaintenanceHistory\BuildAssetMaintenanceHistory;
use App\Services\AssetTagService;
use App\Support\Assets\AssetFieldStatus;
use App\Support\SizeRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    /**
     * Condition values a request may set, resolved from the vocabulary rather
     * than a constant: LDC adds and retires these through the Admin UI, and a
     * hardcoded list would reject a value the picker had just offered.
     *
     * Active rows only — a retired condition stays readable on the assets that
     * already carry it, but must not be selectable for new ones.
     *
     * @return list<string>
     */
    private function activeConditionValues(): array
    {
        return MasterDataItem::activeIn(MasterDataItem::ASSET_CONDITIONS)
            ->pluck('value')
            ->all();
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Asset::class);

        $results = app(AssetIndexQuery::class)->build($request);

        return AssetResource::collection($results)->toResponse($request);
    }

    public function show(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $asset->load(['currentLocation', 'maintenanceCategory']);

        return (new AssetResource($asset))->toResponse($request);
    }

    public function store(Request $request, CreateAsset $action): JsonResponse
    {
        Gate::authorize('create', Asset::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'erp_asset_code' => ['required', 'string', 'max:255', 'unique:assets,erp_asset_code'],
            'description' => ['nullable', 'string'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            // Subset, not the whole enum: `at_the_field` is derived from location.
            'operational_status' => ['nullable', Rule::in(OperationalStatus::manuallySelectableValues())],
            'condition_status' => ['nullable', Rule::in($this->activeConditionValues())],
            'maintenance_status' => ['nullable', 'string', 'in:enrolled,withdrawn'],
            'asset_kind' => ['nullable', 'string', 'in:asset,package,component'],
            'current_location_id' => ['nullable', 'exists:locations,id'],
        ]);

        // Only Admin/Manager may set maintenance lifecycle fields
        $user = $request->user();
        $lifecycleFields = ['maintenance_status', 'asset_kind'];
        $hasLifecycleFields = ! empty(array_intersect_key($validated, array_flip($lifecycleFields)));

        if ($hasLifecycleFields && ! $user->hasRole(RoleCode::ADMINISTRATOR) && ! $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return response()->json([
                'message' => 'Only administrators and maintenance managers can change lifecycle fields.',
            ], 403);
        }

        $asset = $action->execute($validated);

        return (new AssetResource($asset->load(['currentLocation', 'maintenanceCategory'])))
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
            'fa_subclass_code' => ['nullable', 'string', 'max:255'],
            // Assigning an existing Maintenance Category or Size to one asset.
            // The category cannot be cleared: every asset must carry one, and
            // "not classified yet" is the Unclassified category, not a null.
            'maintenance_category_id' => ['sometimes', 'integer', 'exists:maintenance_categories,id'],
            'size_inches' => ['nullable', 'string', 'max:32', new SizeRule],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            // Not `Rule::enum`: that would accept `at_the_field`, which only a
            // location change may write. See OperationalStatus::manuallySelectable().
            'operational_status' => ['nullable', Rule::in(OperationalStatus::manuallySelectableValues())],
            'condition_status' => ['nullable', Rule::in($this->activeConditionValues())],
            'maintenance_status' => ['nullable', 'string', 'in:enrolled,withdrawn'],
            'asset_kind' => ['nullable', 'string', 'in:asset,package,component'],
            'is_active' => ['nullable', 'boolean'],
            'asset_tag' => ['nullable', 'string', 'max:15'],
            'asset_tag_override_reason' => ['nullable', 'string'],
            'current_location_id' => ['nullable', 'exists:locations,id'],
            'location_notes' => ['nullable', 'string'],
        ]);

        // Only Admin/Manager may change maintenance lifecycle fields
        $user = $request->user();
        $lifecycleFields = ['maintenance_status', 'asset_kind'];
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

            // Q1: same user-move rules as POST /assets/{id}/location. Applied at
            // both entry points and never inside UpdateAssetLocation, which
            // StartWorkOrder also calls.
            try {
                AssetFieldStatus::guardManualMove($asset, $location);
            } catch (DomainException $e) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            $asset = $locationAction->execute(
                $asset,
                $location,
                null,
                $validated['location_notes'] ?? null,
                $request->user()->id
            );
        }

        // --- Update operational fields ---
        $fieldUpdates = array_intersect_key(
            $validated,
            array_flip(['name', 'description', 'fa_subclass_code', 'maintenance_category_id', 'size_inches', 'serial_number', 'model', 'manufacturer', 'operational_status', 'condition_status', 'is_active', 'asset_tag', 'asset_tag_override_reason', 'maintenance_status', 'asset_kind'])
        );

        try {
            $asset = app(UpdateAssetFields::class)->execute($asset, $fieldUpdates);
        } catch (DomainException $e) {
            return response()->json([
                'errors' => ['asset_tag' => [$e->getMessage()]],
            ], 409);
        }

        return (new AssetResource($asset->fresh()->load(['currentLocation', 'maintenanceCategory'])))->toResponse($request);
    }

    public function meterReadings(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $readings = $asset->meterReadings()->orderByDesc('reading_at')->get();

        return AssetMeterReadingResource::collection($readings)->toResponse($request);
    }

    public function locationHistory(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        $history = $asset->locationHistories()
            ->with(['fromLocation', 'toLocation'])
            ->orderByDesc('effective_at')
            ->get();

        return AssetLocationHistoryResource::collection($history)->toResponse($request);
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
        Gate::authorize('viewAny', Asset::class);

        $request->validate(['tag' => ['required', 'string', 'max:15']]);

        $asset = Asset::where('asset_tag', $request->query('tag'))->firstOrFail();

        return (new AssetResource($asset->load(['currentLocation', 'maintenanceCategory'])))->toResponse($request);
    }
}
