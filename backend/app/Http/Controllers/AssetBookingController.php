<?php

namespace App\Http\Controllers;

use App\Actions\Assets\ToggleAssetBooking;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetBookingController extends Controller
{
    public function book(Request $request, Asset $asset, ToggleAssetBooking $action): JsonResponse
    {
        Gate::authorize('toggleBooking', $asset);

        try {
            $asset = $action->execute($asset, book: true);

            return (new AssetResource($asset))
                ->additional(['message' => 'Asset booked.'])
                ->response()
                ->setStatusCode(200);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function unbook(Request $request, Asset $asset, ToggleAssetBooking $action): JsonResponse
    {
        Gate::authorize('toggleBooking', $asset);

        try {
            $asset = $action->execute($asset, book: false);

            return (new AssetResource($asset))
                ->additional(['message' => 'Asset unbooked.'])
                ->response()
                ->setStatusCode(200);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
