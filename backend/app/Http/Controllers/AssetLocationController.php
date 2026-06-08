<?php

namespace App\Http\Controllers;

use App\Actions\Assets\UpdateAssetLocation;
use App\Models\Asset;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetLocationController extends Controller
{
    public function update(Request $request, Asset $asset, UpdateAssetLocation $action): JsonResponse
    {
        Gate::authorize('updateLocation', $asset);

        $validated = $request->validate([
            'location_id' => ['required', 'exists:locations,id'],
            'reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! $asset->is_active) {
            return response()->json(['message' => 'Cannot update location for an inactive asset.'], 422);
        }

        $location = Location::findOrFail($validated['location_id']);

        if (! $location->is_active) {
            return response()->json(['message' => 'Cannot assign an inactive location.'], 422);
        }

        $asset = $action->execute(
            $asset,
            $location,
            $validated['reason'] ?? null,
            $validated['notes'] ?? null,
            auth()->id()
        );

        return response()->json(['message' => 'Asset location updated.', 'data' => $asset]);
    }
}
