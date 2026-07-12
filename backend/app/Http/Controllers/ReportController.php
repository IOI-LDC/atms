<?php

namespace App\Http\Controllers;

use App\Enums\AssetKind;
use App\Enums\OperationalStatus;
use App\Http\Resources\OverduePmReportItemResource;
use App\Http\Resources\MeterProgressionReportItemResource;
use App\Http\Resources\PartsConsumptionReportItemResource;
use App\Http\Resources\PmSuppressionReportItemResource;
use App\Http\Resources\UpcomingPmItemResource;
use App\Http\Resources\WorkOrderBacklogItemResource;
use App\Models\User;
use App\Queries\Reports\AgingBuckets;
use App\Queries\Reports\AssetsByLocationReportQuery;
use App\Queries\Reports\BadActorReportQuery;
use App\Queries\Reports\BookingReportQuery;
use App\Queries\Reports\MtbfReportQuery;
use App\Queries\Reports\MttrReportQuery;
use App\Queries\Reports\TechnicianWorkloadReportQuery;
use App\Queries\Reports\AssetMovementReportQuery;
use App\Queries\Reports\FormResultsReportQuery;
use App\Queries\Reports\MeterProgressionReportQuery;
use App\Queries\Reports\ThroughputReportQuery;
use App\Queries\Reports\OperationalStatusDistributionReportQuery;
use App\Queries\Reports\OverduePmReportQuery;
use App\Queries\Reports\PartsConsumptionReportQuery;
use App\Queries\Reports\PmComplianceReportQuery;
use App\Queries\Reports\PmCoverageReportQuery;
use App\Queries\Reports\PmSuppressionReportQuery;
use App\Queries\Reports\UpcomingPmReportQuery;
use App\Queries\Reports\WorkOrderBacklogReportQuery;
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
            'fa_subclass_code' => ['nullable', 'string', 'max:255'],
            'asset_kind' => ['nullable', Rule::enum(AssetKind::class)],
            'operational_status' => ['nullable', Rule::enum(OperationalStatus::class)],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $result = app(AssetsByLocationReportQuery::class)->handle([
            'fa_subclass_code' => $filters['fa_subclass_code'] ?? null,
            'asset_kind' => $filters['asset_kind'] ?? null,
            'operational_status' => $filters['operational_status'] ?? null,
            'include_inactive' => (bool) ($filters['include_inactive'] ?? false),
        ]);

        return response()->json($result);
    }

    public function meterProgression(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'usage_reading_type_id' => ['nullable', 'exists:usage_reading_types,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

        $result = app(MeterProgressionReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 25),
            $from,
            $to,
            [
                'asset_id' => $filters['asset_id'] ?? null,
                'usage_reading_type_id' => $filters['usage_reading_type_id'] ?? null,
            ]
        );

        $result['paginator']->appends($request->query());

        return MeterProgressionReportItemResource::collection($result['paginator'])
            ->additional(['summary' => $result['summary']])
            ->toResponse($request);
    }
    public function overduePm(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'location_id' => ['nullable', 'exists:locations,id'],
            'pm_rule_id' => ['nullable', 'exists:pm_rules,id'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'bucket' => ['nullable', Rule::in(AgingBuckets::BUCKETS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $result = app(OverduePmReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 25),
            [
                'location_id' => $filters['location_id'] ?? null,
                'pm_rule_id' => $filters['pm_rule_id'] ?? null,
                'priority' => $filters['priority'] ?? null,
                'bucket' => $filters['bucket'] ?? null,
            ]
        );

        $result['paginator']->appends($request->query());

        return OverduePmReportItemResource::collection($result['paginator'])
            ->additional(['summary' => $result['summary']])
            ->toResponse($request);
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

        $filters = $request->validate([
            'location_id' => ['nullable', 'exists:locations,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'both'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $result = app(WorkOrderBacklogReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 25),
            [
                'location_id' => $filters['location_id'] ?? null,
                'assigned_to' => $filters['assigned_to'] ?? null,
                'priority' => $filters['priority'] ?? null,
                'status' => $filters['status'] ?? null,
            ]
        );

        $result['paginator']->appends($request->query());

        return WorkOrderBacklogItemResource::collection($result['paginator'])
            ->additional(['summary' => $result['summary']])
            ->toResponse($request);
    }

    public function mtbf(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', Rule::in(['asset', 'category', 'location'])],
            'location_id' => ['nullable', 'exists:locations,id'],
            'fa_subclass_code' => ['nullable', 'string', 'max:255'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : now();

        $result = app(MtbfReportQuery::class)->handle(
            $from,
            $to,
            $filters['group_by'] ?? 'asset',
            [
                'location_id' => $filters['location_id'] ?? null,
                'fa_subclass_code' => $filters['fa_subclass_code'] ?? null,
            ]
        );

        return response()->json($result);
    }

    public function mttr(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', Rule::in(['asset', 'category', 'technician'])],
            'location_id' => ['nullable', 'exists:locations,id'],
            'fa_subclass_code' => ['nullable', 'string', 'max:255'],
            'technician_id' => ['nullable', 'exists:users,id'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : now();

        $result = app(MttrReportQuery::class)->handle(
            $from,
            $to,
            $filters['group_by'] ?? 'asset',
            [
                'location_id' => $filters['location_id'] ?? null,
                'fa_subclass_code' => $filters['fa_subclass_code'] ?? null,
                'technician_id' => $filters['technician_id'] ?? null,
            ]
        );

        return response()->json($result);
    }

    public function badActors(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', Rule::in(['asset', 'category', 'location'])],
            'location_id' => ['nullable', 'exists:locations,id'],
            'fa_subclass_code' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : now();

        $result = app(BadActorReportQuery::class)->handle(
            $from,
            $to,
            $filters['group_by'] ?? 'asset',
            [
                'location_id' => $filters['location_id'] ?? null,
                'fa_subclass_code' => $filters['fa_subclass_code'] ?? null,
                'limit' => $filters['limit'] ?? null,
            ]
        );

        return response()->json($result);
    }

    public function pmCompliance(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
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

    public function pmCoverage(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'location_id' => ['nullable', 'exists:locations,id'],
            'asset_kind' => ['nullable', Rule::enum(AssetKind::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $result = app(PmCoverageReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 25),
            [
                'location_id' => $filters['location_id'] ?? null,
                'asset_kind' => $filters['asset_kind'] ?? null,
            ]
        );

        $result['paginator']->appends($request->query());

        $paginatorArray = $result['paginator']->toArray();

        return response()->json([
            'summary' => $result['summary'],
            'data' => $paginatorArray['data'],
            'links' => $paginatorArray['links'] ?? [],
            'meta' => $paginatorArray['meta'] ?? [],
        ]);
    }

    public function booking(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'location_id' => ['nullable', 'exists:locations,id'],
            'asset_kind' => ['nullable', Rule::enum(AssetKind::class)],
        ]);

        $result = app(BookingReportQuery::class)->handle([
            'location_id' => $filters['location_id'] ?? null,
            'asset_kind' => $filters['asset_kind'] ?? null,
        ]);

        return response()->json($result);
    }

    public function technicianWorkload(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(30);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : now();

        $result = app(TechnicianWorkloadReportQuery::class)->handle($from, $to);

        return response()->json($result);
    }

    public function throughput(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(30);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : now();

        $result = app(ThroughputReportQuery::class)->handle($from, $to);

        return response()->json($result);
    }

    public function partsConsumption(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'part_id' => ['nullable', 'exists:parts,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'fa_subclass_code' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

        $result = app(PartsConsumptionReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 25),
            $from,
            $to,
            [
                'part_id' => $filters['part_id'] ?? null,
                'asset_id' => $filters['asset_id'] ?? null,
                'fa_subclass_code' => $filters['fa_subclass_code'] ?? null,
            ]
        );

        $result['paginator']->appends($request->query());

        return PartsConsumptionReportItemResource::collection($result['paginator'])
            ->additional(['summary' => $result['summary']])
            ->toResponse($request);
    }

    public function pmSuppression(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'pm_rule_id' => ['nullable', 'exists:pm_rules,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'decision_type' => ['nullable', Rule::in(['rejected', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

        $result = app(PmSuppressionReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 25),
            $from,
            $to,
            [
                'pm_rule_id' => $filters['pm_rule_id'] ?? null,
                'asset_id' => $filters['asset_id'] ?? null,
                'decision_type' => $filters['decision_type'] ?? null,
            ]
        );

        $result['paginator']->appends($request->query());

        return PmSuppressionReportItemResource::collection($result['paginator'])
            ->additional(['summary' => $result['summary']])
            ->toResponse($request);
    }

    public function formResults(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(30);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : now();

        $result = app(FormResultsReportQuery::class)->handle($from, $to);

        return response()->json($result);
    }

    public function assetMovement(Request $request): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'from_location_id' => ['nullable', 'exists:locations,id'],
            'to_location_id' => ['nullable', 'exists:locations,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : now();

        $result = app(AssetMovementReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 25),
            $from,
            $to,
            [
                'asset_id' => $filters['asset_id'] ?? null,
                'from_location_id' => $filters['from_location_id'] ?? null,
                'to_location_id' => $filters['to_location_id'] ?? null,
            ]
        );

        $result['paginator']->appends($request->query());

        $paginatorArray = $result['paginator']->toArray();

        return response()->json([
            'summary' => $result['summary'],
            'data' => $paginatorArray['data'],
            'links' => [
                'first' => $paginatorArray['path'].'?per_page='.$paginatorArray['per_page'],
                'last' => null,
                'prev' => $paginatorArray['prev_page_url'],
                'next' => $paginatorArray['next_page_url'],
            ],
            'meta' => [
                'path' => $paginatorArray['path'],
                'per_page' => $paginatorArray['per_page'],
                'next_cursor' => $paginatorArray['next_cursor'],
                'prev_cursor' => $paginatorArray['prev_cursor'],
                'current_page' => 1,
            ],
        ]);
    }
}
