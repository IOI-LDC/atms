<?php

namespace App\Http\Controllers;

use App\Enums\AssetKind;
use App\Enums\OperationalStatus;
use App\Http\Resources\UpcomingPmItemResource;
use App\Models\User;
use App\Queries\Reports\AssetsByLocationReportQuery;
use App\Queries\Reports\OperationalStatusDistributionReportQuery;
use App\Queries\Reports\PmComplianceReportQuery;
use App\Queries\Reports\UpcomingPmReportQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function upcomingPm(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'pm_rule_id' => ['nullable', 'exists:pm_rules,id'],
        ]);

        $result = app(UpcomingPmReportQuery::class)->handle(
            (int) ($filters['days'] ?? 30),
            [
                'location_id' => $filters['location_id'] ?? null,
                'pm_rule_id' => $filters['pm_rule_id'] ?? null,
            ]
        );

        return response()->json([
            'summary' => $result['summary'],
            'items' => UpcomingPmItemResource::collection($result['items'])->resolve($request),
        ]);
    }

    public function assetsByLocation(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'category' => ['nullable', 'string', 'max:100'],
            'asset_kind' => ['nullable', Rule::enum(AssetKind::class)],
            'operational_status' => ['nullable', Rule::enum(OperationalStatus::class)],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $result = app(AssetsByLocationReportQuery::class)->handle([
            'category' => $filters['category'] ?? null,
            'asset_kind' => $filters['asset_kind'] ?? null,
            'operational_status' => $filters['operational_status'] ?? null,
            'include_inactive' => (bool) ($filters['include_inactive'] ?? false),
        ]);

        return response()->json($result);
    }

    public function pmCompliance(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'group_by' => ['nullable', Rule::in(['rule', 'asset', 'location'])],
            'location_id' => ['nullable', 'exists:locations,id'],
            'pm_rule_id' => ['nullable', 'exists:pm_rules,id'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : now();

        $result = app(PmComplianceReportQuery::class)->handle(
            $from,
            $to,
            $filters['group_by'] ?? 'rule',
            [
                'location_id' => $filters['location_id'] ?? null,
                'pm_rule_id' => $filters['pm_rule_id'] ?? null,
            ]
        );

        return response()->json($result);
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
