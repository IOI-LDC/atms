<?php

namespace App\Http\Controllers;

use App\Enums\AssetKind;
use App\Models\User;
use App\Queries\Reports\OperationalStatusDistributionReportQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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

        $filters = $request->validate([
            'asset_kind' => ['nullable', Rule::enum(AssetKind::class)],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $result = app(OperationalStatusDistributionReportQuery::class)->handle([
            'asset_kind' => $filters['asset_kind'] ?? null,
            'include_inactive' => (bool) ($filters['include_inactive'] ?? false),
        ]);

        return response()->json($result);
    }

    public function woBacklog(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        return response()->json(['summary' => [], 'items' => []]);
    }
}
