<?php

namespace App\Http\Controllers;

use App\Http\Resources\AssetLocationHistoryResource;
use App\Http\Resources\DashboardKpiResource;
use App\Models\User;
use App\Queries\Dashboard\Kpis\AssetHealthKpiQuery;
use App\Queries\Dashboard\Kpis\ProcessPerformanceKpiQuery;
use App\Queries\Dashboard\Kpis\ReliabilityKpiQuery;
use App\Queries\Dashboard\Kpis\WorkforceKpiQuery;
use App\Queries\Dashboard\RecentlyRelocatedAssetsQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardKpiController extends Controller
{
    public function index(Request $request): DashboardKpiResource
    {
        Gate::authorize('viewDashboard', User::class);

        $days = 90;
        $now = now();
        $since = $now->copy()->subDays($days);

        return DashboardKpiResource::make([
            'window' => ['days' => $days, 'from' => $since, 'to' => $now],
            'kpis' => array_merge(
                app(ReliabilityKpiQuery::class)->handle($since, $now, $days),
                app(ProcessPerformanceKpiQuery::class)->handle($since, $now),
                app(AssetHealthKpiQuery::class)->handle(),
                app(WorkforceKpiQuery::class)->handle($since, $now),
            ),
            'recently_relocated_assets' => AssetLocationHistoryResource::collection(
                app(RecentlyRelocatedAssetsQuery::class)->handle($since, $now)
            )->resolve($request),
        ]);
    }
}
