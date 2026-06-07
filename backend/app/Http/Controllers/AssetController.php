<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AssetController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Asset::class);
        return response()->json(['data' => Asset::all()]);
    }

    public function show(Asset $asset): JsonResponse
    {
        // Simple view access check for now; Task 14 refines role scoping
        return response()->json(['data' => $asset]);
    }

    public function meterReadings(Asset $asset): JsonResponse
    {
        return response()->json(['data' => $asset->meterReadings()->orderByDesc('reading_at')->get()]);
    }

    public function locationHistory(Asset $asset): JsonResponse
    {
        return response()->json(['data' => $asset->locationHistories()->orderByDesc('effective_at')->get()]);
    }
}
