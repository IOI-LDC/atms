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

    public function storeMasterDataItem(Request $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);

        $validated = $request->validate([
            'group_key' => ['required', 'string'],
            'value' => ['required', 'string'],
            'label' => ['required', 'string'],
            'sort_order' => ['integer'],
            'is_active' => ['boolean'],
        ]);

        $item = MasterDataItem::create($validated);

        return response()->json(['data' => $item], 201);
    }

    public function storeUsageReadingType(Request $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class); // Reusing Master Data admin-only rule

        $validated = $request->validate([
            'name' => ['required', 'string'],
            'unit' => ['required', 'string'],
            'is_active' => ['boolean'],
        ]);

        $type = UsageReadingType::create($validated);

        return response()->json(['data' => $type], 201);
    }
}
