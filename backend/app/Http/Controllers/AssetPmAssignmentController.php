<?php

namespace App\Http\Controllers;

use App\Actions\Pm\DeactivateAssetPmAssignment;
use App\Actions\Pm\EvaluatePmRule;
use App\Actions\Pm\ReactivateAssetPmAssignment;
use App\Http\Resources\AssetPmAssignmentResource;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\Asset;
use App\Models\PmRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AssetPmAssignmentController extends Controller
{
    public function index(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('viewAny', AssetPmAssignment::class);

        $query = $asset->pmAssignments()
            ->with(['asset', 'pmRule.usageReadingType', 'assignedBy']);

        // Default: active only. ?is_active=0 lists deactivated assignments
        // (reachable for reactivation). ?is_active=all lists every assignment.
        $isActiveInput = $request->input('is_active');
        if ($isActiveInput !== 'all') {
            $isActive = filter_var($isActiveInput ?? 1, FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        $assignments = $query->get();

        return AssetPmAssignmentResource::collection($assignments)->toResponse($request);
    }

    public function store(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('create', AssetPmAssignment::class);

        $validated = $request->validate([
            'pm_rule_id' => ['required', 'exists:pm_rules,id'],
        ]);

        $rule = PmRule::findOrFail($validated['pm_rule_id']);

        if (! $rule->is_active) {
            return response()->json(['message' => 'Only active PM rules can be assigned.'], 422);
        }

        $exists = AssetPmAssignment::where('asset_id', $asset->id)
            ->where('pm_rule_id', $rule->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'This PM rule is already assigned to this asset.'], 409);
        }

        // Initial baseline: one full grace interval before the first PM is due.
        $lastTriggeredDate = now()->toDateString();
        $lastTriggeredReading = null;

        if ($rule->usage_reading_type_id) {
            $lastTriggeredReading = AssetMeterReading::where('asset_id', $asset->id)
                ->where('usage_reading_type_id', $rule->usage_reading_type_id)
                ->whereNotNull('confirmed_at')
                ->orderByDesc('reading_at')
                ->value('reading_value');
        }

        $assignment = DB::transaction(function () use ($asset, $rule, $lastTriggeredDate, $lastTriggeredReading, $request) {
            $created = AssetPmAssignment::create([
                'asset_id' => $asset->id,
                'pm_rule_id' => $rule->id,
                'last_triggered_date' => $lastTriggeredDate,
                'last_triggered_reading' => $lastTriggeredReading,
                'is_active' => true,
                'assigned_by' => auth()->id(),
            ]);

            $created->load(['asset', 'pmRule.usageReadingType', 'assignedBy']);
            app(AuditLogger::class)->log('pm_assignment.created', $created, [], $created->toArray());

            return $created;
        });

        return (new AssetPmAssignmentResource($assignment))->toResponse($request)->setStatusCode(201);
    }

    public function show(Request $request, Asset $asset, AssetPmAssignment $assignment): JsonResponse
    {
        Gate::authorize('view', $assignment);
        abort_unless($assignment->asset_id === $asset->id, 404);

        $assignment->load(['asset', 'pmRule.usageReadingType', 'assignedBy', 'suppressions']);

        return (new AssetPmAssignmentResource($assignment))->toResponse($request);
    }

    public function deactivate(Asset $asset, AssetPmAssignment $assignment, DeactivateAssetPmAssignment $action): JsonResponse
    {
        Gate::authorize('deactivate', $assignment);
        abort_unless($assignment->asset_id === $asset->id, 404);

        try {
            $result = $action->execute($assignment, auth()->id());

            return response()->json(['message' => 'PM assignment deactivated.', 'data' => $result]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function reactivate(Asset $asset, AssetPmAssignment $assignment, ReactivateAssetPmAssignment $action): JsonResponse
    {
        Gate::authorize('reactivate', $assignment);
        abort_unless($assignment->asset_id === $asset->id, 404);

        try {
            $result = $action->execute($assignment, auth()->id());

            return response()->json(['message' => 'PM assignment reactivated.', 'data' => $result]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function evaluate(Asset $asset, AssetPmAssignment $assignment, EvaluatePmRule $action): JsonResponse
    {
        Gate::authorize('evaluate', $assignment);
        abort_unless($assignment->asset_id === $asset->id, 404);

        try {
            $mr = $action->execute($assignment, auth()->id());

            if ($mr === null) {
                return response()->json(['message' => 'PM assignment is not due.']);
            }

            return response()->json(['message' => 'PM request generated.', 'data' => $mr], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function evaluateAll(Request $request, EvaluatePmRule $action): JsonResponse
    {
        Gate::authorize('evaluateAll', AssetPmAssignment::class);

        $assignments = AssetPmAssignment::where('is_active', true)
            ->whereHas('pmRule', fn ($q) => $q->where('is_active', true))
            ->with('pmRule')
            ->get();

        $generated = 0;

        foreach ($assignments as $assignment) {
            try {
                $mr = $action->execute($assignment, auth()->id());
                if ($mr !== null) {
                    $generated++;
                }
            } catch (\DomainException $e) {
                continue;
            }
        }

        return response()->json([
            'evaluated' => $assignments->count(),
            'generated' => $generated,
        ]);
    }
}
