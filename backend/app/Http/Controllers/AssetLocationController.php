<?php

namespace App\Http\Controllers;

use App\Actions\Assets\UpdateAssetLocation;
use App\Models\Asset;
use App\Models\Location;
use App\Support\Assets\AssetFieldStatus;
use App\Support\Assets\AssetWorkEligibility;
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

        // Was a hand-rolled `is_active` check returning 422. It now goes through
        // the shared guard so both axes block and the message names the cause;
        // 409 matches the house pattern for a precondition failure on an
        // existing resource, and lines this route up with the other entry points.
        try {
            AssetWorkEligibility::guard($asset, 'update location');
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $location = Location::findOrFail($validated['location_id']);

        if (! $location->is_active) {
            return response()->json(['message' => 'Cannot assign an inactive location.'], 422);
        }

        // Q1: this is a user-initiated move, so the manual-move rules apply.
        // Deliberately here and in AssetController::update rather than inside
        // UpdateAssetLocation, which StartWorkOrder also calls.
        try {
            AssetFieldStatus::guardManualMove($asset, $location);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $asset = $action->execute(
            $asset,
            $location,
            $validated['reason'] ?? null,
            $validated['notes'] ?? null,
            $request->user()->id
        );

        return response()->json(['message' => 'Asset location updated.', 'data' => $asset]);
    }
}
