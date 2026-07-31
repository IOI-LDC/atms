<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Http\Resources\AssetPmAssignmentResource;
use App\Http\Resources\MaintenanceRequestResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\User;
use App\Queries\Dashboard\OpenWorkOrdersQuery;
use App\Queries\Dashboard\PendingMaintenanceRequestsQuery;
use App\Queries\Dashboard\RecentlyClosedWorkOrdersQuery;
use App\Queries\Pm\OverduePmQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $isTech = $user->hasRole(RoleCode::TECHNICIAN);
        $isRequester = $user->hasRole(RoleCode::REQUESTER);

        $summary = [];
        $widgets = [];

        $showPendingMrs = $isAdmin || $isManager || $isRequester;
        $showOpenWos = $isAdmin || $isManager || $isTech || $isRequester;
        $showOverduePm = $isAdmin || $isManager || $isRequester;
        $showRecentlyClosed = $isAdmin || $isManager || $isRequester;

        if ($showPendingMrs) {
            $pending = app(PendingMaintenanceRequestsQuery::class)->handle($user);
            $summary['pending_maintenance_requests'] = $pending['count'];
            $widgets['pending_maintenance_requests'] = MaintenanceRequestResource::collection($pending['items'])->resolve($request);
        }

        if ($showOpenWos) {
            $open = app(OpenWorkOrdersQuery::class)->handle($user);
            $summary['open_work_orders'] = $open['count'];
            $widgets['open_work_orders'] = WorkOrderResource::collection($open['items'])->resolve($request);
        }

        if ($showOverduePm) {
            $overdueAssignments = app(OverduePmQuery::class)->execute(5);
            $summary['overdue_pm_assignments'] = $overdueAssignments->count();
            $widgets['overdue_pm_assignments'] = AssetPmAssignmentResource::collection($overdueAssignments)->resolve($request);
        }

        if ($showRecentlyClosed) {
            $closed = app(RecentlyClosedWorkOrdersQuery::class)->handle();
            $summary['recently_closed_work_orders'] = $closed['count'];
            $widgets['recently_closed_work_orders'] = WorkOrderResource::collection($closed['items'])->resolve($request);
        }

        return response()->json(array_merge(['summary' => $summary], $widgets));
    }
}
