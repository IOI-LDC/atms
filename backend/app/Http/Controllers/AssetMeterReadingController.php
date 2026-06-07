<?php

namespace App\Http\Controllers;

use App\Actions\Assets\ConfirmMeterReading;
use App\Actions\Assets\RecordMeterReading;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\UsageReadingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetMeterReadingController extends Controller
{
    public function store(Request $request, Asset $asset, RecordMeterReading $action): JsonResponse
    {
        Gate::authorize('create', AssetMeterReading::class);

        $validated = $request->validate([
            'usage_reading_type_id' => ['required', 'exists:usage_reading_types,id'],
            'reading_value' => ['required', 'numeric'],
            'reading_at' => ['required', 'date'],
            'source' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $readingType = UsageReadingType::findOrFail($validated['usage_reading_type_id']);

        $reading = $action->execute(
            $asset,
            $readingType,
            (float) $validated['reading_value'],
            \Carbon\Carbon::parse($validated['reading_at']),
            $validated['source'],
            auth()->id(),
            null,
            $validated['notes'] ?? null
        );

        return response()->json(['message' => 'Meter reading recorded.', 'data' => $reading], 201);
    }

    public function confirm(Request $request, Asset $asset, AssetMeterReading $reading, ConfirmMeterReading $action): JsonResponse
    {
        Gate::authorize('confirm', AssetMeterReading::class);

        if ($reading->asset_id !== $asset->id) {
            abort(404);
        }

        try {
            $reading = $action->execute($reading, auth()->id());
            return response()->json(['message' => 'Meter reading confirmed.', 'data' => $reading]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
