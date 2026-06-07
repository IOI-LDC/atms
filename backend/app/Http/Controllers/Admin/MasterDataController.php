<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\MasterDataItem;
use App\Models\UsageReadingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MasterDataController extends Controller
{
    public function indexLocations(): JsonResponse
    {
        Gate::authorize('manage', Location::class);
        return response()->json(['data' => Location::all()]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        Gate::authorize('manage', Location::class);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'exists:locations,id'],
            'name' => ['required', 'string'],
            'type' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $location = Location::create($validated);

        return response()->json(['data' => $location], 201);
    }

    public function updateLocation(Request $request, Location $location): JsonResponse
    {
        Gate::authorize('manage', Location::class);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'exists:locations,id'],
            'name' => ['string'],
            'type' => ['string'],
            'code' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $location->update($validated);

        return response()->json(['data' => $location]);
    }

    public function indexMasterData(string $groupKey): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);
        $items = MasterDataItem::where('group_key', $groupKey)->orderBy('sort_order')->get();
        return response()->json(['data' => $items]);
    }

    public function storeMasterDataItem(Request $request, string $groupKey): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);

        $validated = $request->validate([
            'value' => ['required', 'string'],
            'label' => ['required', 'string'],
            'sort_order' => ['integer'],
            'is_active' => ['boolean'],
        ]);

        $validated['group_key'] = $groupKey;

        $item = MasterDataItem::create($validated);

        return response()->json(['data' => $item], 201);
    }

    public function updateMasterDataItem(Request $request, MasterDataItem $item): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);

        $validated = $request->validate([
            'value' => ['string'],
            'label' => ['string'],
            'sort_order' => ['integer'],
            'is_active' => ['boolean'],
        ]);

        $item->update($validated);

        return response()->json(['data' => $item]);
    }

    public function indexUsageReadingTypes(): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);
        return response()->json(['data' => UsageReadingType::all()]);
    }

    public function storeUsageReadingType(Request $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);

        $validated = $request->validate([
            'name' => ['required', 'string'],
            'unit' => ['required', 'string'],
            'is_active' => ['boolean'],
        ]);

        $type = UsageReadingType::create($validated);

        return response()->json(['data' => $type], 201);
    }
    
    public function updateUsageReadingType(Request $request, UsageReadingType $type): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);

        $validated = $request->validate([
            'name' => ['string'],
            'unit' => ['string'],
            'is_active' => ['boolean'],
        ]);

        $type->update($validated);

        return response()->json(['data' => $type]);
    }
}
