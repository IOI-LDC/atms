<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function upcomingPm(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        return response()->json(['summary' => [], 'items' => []]);
    }

    public function assetsByLocation(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        return response()->json(['summary' => [], 'items' => []]);
    }

    public function pmCompliance(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        return response()->json(['summary' => [], 'items' => []]);
    }

    public function overduePm(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        return response()->json(['summary' => [], 'items' => []]);
    }

    public function assetStatusDistribution(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        return response()->json(['summary' => [], 'items' => []]);
    }

    public function woBacklog(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        return response()->json(['summary' => [], 'items' => []]);
    }
}
