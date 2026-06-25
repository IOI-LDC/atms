<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class LocationController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Location::class);

        $locations = Location::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'code', 'description', 'is_active']);

        return response()->json(['data' => $locations]);
    }
}
