<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Http\Resources\MaintenanceRequestResource;
use App\Http\Resources\PmRuleResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use App\Queries\Pm\OverduePmQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
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
            $mrQuery = MaintenanceRequest::with(['asset', 'createdBy', 'workOrder', 'attachments'])
                ->where('status', 'pending_review');

            if ($isRequester) {
                $mrQuery->where('created_by', $user->id);
            }

            $summary['pending_maintenance_requests'] = (clone $mrQuery)->count();

            $mrList = (clone $mrQuery)->orderByDesc('created_at')->limit(5)->get();
            $widgets['pending_maintenance_requests'] = MaintenanceRequestResource::collection($mrList)->resolve($request);
        }

        if ($showOpenWos) {
            $woQuery = WorkOrder::with(['asset', 'assignedTo', 'maintenanceRequest', 'parts.part', 'attachments'])
                ->whereIn('status', [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS]);

            if ($isTech) {
                $woQuery->where('assigned_to_user_id', $user->id);
            }

            $summary['open_work_orders'] = (clone $woQuery)->count();

            $woList = (clone $woQuery)->orderByDesc('created_at')->limit(5)->get();
            $widgets['open_work_orders'] = WorkOrderResource::collection($woList)->resolve($request);
        }

        if ($showOverduePm) {
            $overdueRules = app(OverduePmQuery::class)->execute(5);
            $summary['overdue_pm_rules'] = $overdueRules->count();
            $widgets['overdue_pm_rules'] = PmRuleResource::collection($overdueRules)->resolve($request);
        }

        if ($showRecentlyClosed) {
            $recentlyClosedQuery = WorkOrder::with(['asset', 'assignedTo', 'maintenanceRequest', 'parts.part', 'attachments'])
                ->where('status', WorkOrderStatus::CLOSED)
                ->where('closed_at', '>=', now()->subDays(30));

            $summary['recently_closed_work_orders'] = (clone $recentlyClosedQuery)->count();

            $recentlyClosed = (clone $recentlyClosedQuery)->orderByDesc('closed_at')->limit(5)->get();
            $widgets['recently_closed_work_orders'] = WorkOrderResource::collection($recentlyClosed)->resolve($request);
        }

        return response()->json(array_merge(['summary' => $summary], $widgets));
    }
}
