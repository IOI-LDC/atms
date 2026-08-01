<?php

namespace App\Http\Controllers;

use App\Enums\AssetKind;
use App\Enums\MaintenanceRequestStatus;
use App\Enums\OperationalStatus;
use App\Enums\WorkOrderStatus;
use App\Http\Resources\AssetLocationHistoryResource;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AssetStatusReportItemResource;
use App\Http\Resources\FormResultReportItemResource;
use App\Http\Resources\MeterProgressionReportItemResource;
use App\Http\Resources\OverduePmReportItemResource;
use App\Http\Resources\PartsConsumptionReportItemResource;
use App\Http\Resources\PmSuppressionReportItemResource;
use App\Http\Resources\UpcomingPmItemResource;
use App\Http\Resources\WorkOrderBacklogItemResource;
use App\Models\UsageReadingType;
use App\Models\User;
use App\Queries\Reports\AgingBuckets;
use App\Queries\Reports\AssetDistributionReportQuery;
use App\Queries\Reports\AssetMovementReportQuery;
use App\Queries\Reports\AssetStatusReportQuery;
use App\Queries\Reports\AssetUsageReportQuery;
use App\Queries\Reports\BadActorReportQuery;
use App\Queries\Reports\BookingReportQuery;
use App\Queries\Reports\FormResultsReportQuery;
use App\Queries\Reports\MeterProgressionReportQuery;
use App\Queries\Reports\MtbfReportQuery;
use App\Queries\Reports\MttrReportQuery;
use App\Queries\Reports\OperationalStatusDistributionReportQuery;
use App\Queries\Reports\OverduePmReportQuery;
use App\Queries\Reports\PartsConsumptionReportQuery;
use App\Queries\Reports\PmComplianceReportQuery;
use App\Queries\Reports\PmCoverageReportQuery;
use App\Queries\Reports\PmSuppressionReportQuery;
use App\Queries\Reports\TechnicianWorkloadReportQuery;
use App\Queries\Reports\ThroughputReportQuery;
use App\Queries\Reports\UpcomingPmReportQuery;
use App\Queries\Reports\WorkOrderBacklogReportQuery;
use App\Support\Reports\CsvReportStreamer;
use App\Support\Reports\ReportCsvColumns;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function upcomingPm(Request $request): JsonResponse
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

    public function assetDistribution(Request $request): JsonResponse|StreamedResponse
    {
        Gate::authorize('viewDashboard', User::class);

        // `group_by` takes a list so the report can cut by any combination of
        // the three at once. A bare string still works — the dashboard and the
        // pre-multigroup links send one.
        $request->merge(['group_by' => (array) $request->query('group_by', [])]);

        $filters = $request->validate([
            'group_by' => ['nullable', 'array', 'max:3'],
            'group_by.*' => [Rule::in(['location', 'maintenance_category', 'size'])],
            'maintenance_category_id' => ['nullable', 'exists:maintenance_categories,id'],
            'asset_kind' => ['nullable', Rule::enum(AssetKind::class)],
            'operational_status' => ['nullable', Rule::enum(OperationalStatus::class)],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        // Location alone stays the default: it is what this report answered
        // before it gained dimensions, and it is the question asked most often.
        $result = app(AssetDistributionReportQuery::class)->handle(
            $filters['group_by'] ?: ['location'],
            [
                'maintenance_category_id' => $filters['maintenance_category_id'] ?? null,
                'asset_kind' => $filters['asset_kind'] ?? null,
                'operational_status' => $filters['operational_status'] ?? null,
                'include_inactive' => (bool) ($filters['include_inactive'] ?? false),
            ]
        );

        if ($this->wantsCsv($request)) {
            return $this->streamCsv(
                'asset-distribution',
                ReportCsvColumns::assetDistribution($result['group_by']),
                $result['items'],
            );
        }

        return response()->json($result);
    }

    public function meterProgression(Request $request): JsonResponse
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

    public function overduePm(Request $request): JsonResponse
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

    public function assetStatusDistribution(Request $request): JsonResponse
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

    public function woBacklog(Request $request): JsonResponse
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

    public function assetUsage(Request $request): JsonResponse|StreamedResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'usage_reading_type_id' => ['nullable', 'exists:usage_reading_types,id'],
            'group_by' => ['nullable', Rule::in(['asset', 'maintenance_category', 'size'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'location_id' => ['nullable', 'exists:locations,id'],
            'maintenance_category_id' => ['nullable', 'exists:maintenance_categories,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Units differ per reading type, so one is always in play. Absent an
        // explicit choice the first active type answers the page on first load.
        $readingType = isset($filters['usage_reading_type_id'])
            ? UsageReadingType::findOrFail($filters['usage_reading_type_id'])
            : UsageReadingType::where('is_active', true)->orderBy('id')->first();

        if ($readingType === null) {
            return response()->json(['message' => 'No active usage reading type is configured.'], 409);
        }

        // Null `from` means "since the asset was first metered" — the baseline
        // then comes from the earliest reading rather than the day before.
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : null;
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

        $result = app(AssetUsageReportQuery::class)->handle(
            $readingType,
            $from,
            $to,
            $filters['group_by'] ?? 'asset',
            [
                'location_id' => $filters['location_id'] ?? null,
                'maintenance_category_id' => $filters['maintenance_category_id'] ?? null,
                'limit' => $filters['limit'] ?? null,
            ]
        );

        if ($this->wantsCsv($request)) {
            return $this->streamCsv(
                'most-used-assets',
                ReportCsvColumns::assetUsage($result['group_by'], $result['reading_type']['unit']),
                $result['items'],
            );
        }

        return response()->json($result);
    }

    public function mtbf(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', Rule::in(['asset', 'maintenance_category', 'size', 'location'])],
            'location_id' => ['nullable', 'exists:locations,id'],
            'maintenance_category_id' => ['nullable', 'exists:maintenance_categories,id'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

        $result = app(MtbfReportQuery::class)->handle(
            $from,
            $to,
            $filters['group_by'] ?? 'asset',
            [
                'location_id' => $filters['location_id'] ?? null,
                'maintenance_category_id' => $filters['maintenance_category_id'] ?? null,
            ]
        );

        return response()->json($result);
    }

    public function mttr(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', Rule::in(['asset', 'maintenance_category', 'size', 'technician'])],
            'location_id' => ['nullable', 'exists:locations,id'],
            'maintenance_category_id' => ['nullable', 'exists:maintenance_categories,id'],
            'technician_id' => ['nullable', 'exists:users,id'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

        $result = app(MttrReportQuery::class)->handle(
            $from,
            $to,
            $filters['group_by'] ?? 'asset',
            [
                'location_id' => $filters['location_id'] ?? null,
                'maintenance_category_id' => $filters['maintenance_category_id'] ?? null,
                'technician_id' => $filters['technician_id'] ?? null,
            ]
        );

        return response()->json($result);
    }

    public function badActors(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', Rule::in(['asset', 'maintenance_category', 'size', 'location'])],
            'location_id' => ['nullable', 'exists:locations,id'],
            'maintenance_category_id' => ['nullable', 'exists:maintenance_categories,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

        $result = app(BadActorReportQuery::class)->handle(
            $from,
            $to,
            $filters['group_by'] ?? 'asset',
            [
                'location_id' => $filters['location_id'] ?? null,
                'maintenance_category_id' => $filters['maintenance_category_id'] ?? null,
                'limit' => $filters['limit'] ?? null,
            ]
        );

        return response()->json($result);
    }

    public function pmCompliance(Request $request): JsonResponse
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
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

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

    /**
     * R-1 Assets Status Report — flat asset register.
     *
     * `from`/`to` filter `updated_at` by default (`date_field=created_at` to
     * switch). They are NOT a point-in-time status filter: operational status is
     * overwritten in place, so past status cannot be reconstructed.
     */
    public function assetStatus(Request $request): JsonResponse|StreamedResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'location_id' => ['nullable', 'exists:locations,id'],
            'operational_status' => ['nullable', Rule::enum(OperationalStatus::class)],
            'asset_kind' => ['nullable', Rule::enum(AssetKind::class)],
            'maintenance_category_id' => ['nullable', 'exists:maintenance_categories,id'],
            'booked' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'date_field' => ['nullable', 'in:created_at,updated_at'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $result = app(AssetStatusReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 50),
            [
                'location_id' => $filters['location_id'] ?? null,
                'operational_status' => $filters['operational_status'] ?? null,
                'asset_kind' => $filters['asset_kind'] ?? null,
                'maintenance_category_id' => $filters['maintenance_category_id'] ?? null,
                'booked' => array_key_exists('booked', $filters) ? (bool) $filters['booked'] : null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'date_field' => $filters['date_field'] ?? null,
            ]
        );

        if ($this->wantsCsv($request)) {
            return $this->streamCsv('asset-status', ReportCsvColumns::assetStatus(), $result['stream']());
        }

        $result['paginator']->appends($request->query());

        return AssetStatusReportItemResource::collection($result['paginator'])
            ->additional(['summary' => $result['summary']])
            ->toResponse($request);
    }

    public function pmCoverage(Request $request): JsonResponse
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

        return AssetResource::collection($result['paginator'])
            ->additional(['summary' => $result['summary']])
            ->toResponse($request);
    }

    public function booking(Request $request): JsonResponse
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

    public function technicianWorkload(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'technician_id' => ['nullable', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

        $result = app(TechnicianWorkloadReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 25),
            $from,
            $to,
            ['technician_id' => $filters['technician_id'] ?? null]
        );

        $result['paginator']->appends($request->query());

        $paginatorArray = $result['paginator']->toArray();

        return response()->json([
            'summary' => $result['summary'],
            'data' => $paginatorArray['data'],
            'meta' => [
                'path' => $paginatorArray['path'],
                'per_page' => $paginatorArray['per_page'],
                'next_cursor' => $paginatorArray['next_cursor'] ?? null,
                'prev_cursor' => $paginatorArray['prev_cursor'] ?? null,
            ],
        ]);
    }

    public function throughput(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $statusValues = array_values(array_unique([
            ...array_column(MaintenanceRequestStatus::cases(), 'value'),
            ...array_column(WorkOrderStatus::cases(), 'value'),
        ]));

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in($statusValues)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

        $result = app(ThroughputReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 25),
            $from,
            $to,
            ['status' => $filters['status'] ?? null]
        );

        $result['paginator']->appends($request->query());

        $paginatorArray = $result['paginator']->toArray();

        return response()->json([
            'summary' => $result['summary'],
            'data' => $paginatorArray['data'],
            'meta' => [
                'path' => $paginatorArray['path'],
                'per_page' => $paginatorArray['per_page'],
                'next_cursor' => $paginatorArray['next_cursor'] ?? null,
                'prev_cursor' => $paginatorArray['prev_cursor'] ?? null,
            ],
        ]);
    }

    public function partsConsumption(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'part_id' => ['nullable', 'exists:parts,id'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'maintenance_category_id' => ['nullable', 'exists:maintenance_categories,id'],
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
                'maintenance_category_id' => $filters['maintenance_category_id'] ?? null,
            ]
        );

        $result['paginator']->appends($request->query());

        return PartsConsumptionReportItemResource::collection($result['paginator'])
            ->additional(['summary' => $result['summary']])
            ->toResponse($request);
    }

    public function pmSuppression(Request $request): JsonResponse
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

    public function formResults(Request $request): JsonResponse
    {
        Gate::authorize('viewDashboard', User::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'asset_id' => ['nullable', 'exists:assets,id'],
            'maintenance_category_id' => ['nullable', 'exists:maintenance_categories,id'],
            'field_uuid' => ['nullable', 'string', 'max:36'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(90);
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

        $result = app(FormResultsReportQuery::class)->handle(
            (int) ($filters['per_page'] ?? 25),
            $from,
            $to,
            [
                'asset_id' => $filters['asset_id'] ?? null,
                'maintenance_category_id' => $filters['maintenance_category_id'] ?? null,
                'field_uuid' => $filters['field_uuid'] ?? null,
            ]
        );

        $result['paginator']->appends($request->query());

        return FormResultReportItemResource::collection($result['paginator'])
            ->additional(['summary' => $result['summary']])
            ->toResponse($request);
    }

    public function assetMovement(Request $request): JsonResponse
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
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now();

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

        return AssetLocationHistoryResource::collection($result['paginator'])
            ->additional(['summary' => $result['summary']])
            ->toResponse($request);
    }

    /**
     * Every report answers the same endpoint in either shape; `?format=csv`
     * switches the serialization, so a CSV can never disagree with the table
     * above it about filters, sorting, or who is allowed to see it.
     */
    private function wantsCsv(Request $request): bool
    {
        return $request->query('format') === 'csv';
    }

    /**
     * @param  array<string, string|\Closure>  $columns
     * @param  iterable<mixed>  $rows
     */
    private function streamCsv(string $slug, array $columns, iterable $rows): StreamedResponse
    {
        return app(CsvReportStreamer::class)->stream($slug, $columns, $rows);
    }
}
