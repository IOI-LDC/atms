<?php

namespace App\Http\Controllers;

use App\Actions\Pm\DeactivatePmRule;
use App\Actions\Pm\ReactivatePmRule;
use App\Enums\PmTriggerType;
use App\Http\Resources\AssetPmAssignmentResource;
use App\Http\Resources\PmRuleResource;
use App\Models\PmRule;
use App\Queries\PmRules\PmRuleIndexQuery;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PmRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PmRule::class);

        $results = app(PmRuleIndexQuery::class)->build($request);

        return PmRuleResource::collection($results)->toResponse($request);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', PmRule::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'maintenance_level' => ['nullable', 'string', 'max:10'],
            'description' => ['nullable', 'string'],
            'trigger_type' => ['required', 'string', 'in:date,reading,date_or_reading'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'required_if:trigger_type,date,date_or_reading'],
            'interval_reading' => ['nullable', 'numeric', 'min:0.01', 'required_if:trigger_type,reading,date_or_reading'],
            'usage_reading_type_id' => ['nullable', 'exists:usage_reading_types,id', 'required_if:trigger_type,reading,date_or_reading'],
        ]);

        $rule = PmRule::create([
            'name' => $validated['name'],
            'maintenance_level' => $validated['maintenance_level'] ?? null,
            'description' => $validated['description'] ?? null,
            'trigger_type' => $validated['trigger_type'],
            'interval_days' => $validated['interval_days'] ?? null,
            'interval_reading' => $validated['interval_reading'] ?? null,
            'usage_reading_type_id' => $validated['usage_reading_type_id'] ?? null,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        $rule->load(['usageReadingType', 'createdBy']);
        $rule->loadCount(['assignments' => fn ($q) => $q->where('is_active', true)]);

        app(AuditLogger::class)->log('pm_rule.created', $rule, [], $rule->toArray());

        return (new PmRuleResource($rule))->toResponse($request)->setStatusCode(201);
    }

    public function show(Request $request, PmRule $pmRule): JsonResponse
    {
        Gate::authorize('view', $pmRule);

        $pmRule->load([
            'usageReadingType',
            'createdBy',
            'assignments.asset',
            'assignments.pmRule.usageReadingType',
            'assignments.assignedBy',
        ]);
        $pmRule->loadCount(['assignments' => fn ($q) => $q->where('is_active', true)]);

        return (new PmRuleResource($pmRule))->toResponse($request);
    }

    public function update(Request $request, PmRule $pmRule): JsonResponse
    {
        Gate::authorize('update', $pmRule);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'maintenance_level' => ['nullable', 'string', 'max:10'],
            'description' => ['nullable', 'string'],
            'interval_days' => ['nullable', 'integer', 'min:1'],
            'interval_reading' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $triggerType = $pmRule->trigger_type;

        if (in_array($triggerType, [PmTriggerType::DATE, PmTriggerType::DATE_OR_READING])) {
            if (array_key_exists('interval_days', $validated) && $validated['interval_days'] === null) {
                return response()->json(['message' => 'interval_days is required for this trigger type.'], 422);
            }
        }

        if (in_array($triggerType, [PmTriggerType::READING, PmTriggerType::DATE_OR_READING])) {
            if (array_key_exists('interval_reading', $validated) && $validated['interval_reading'] === null) {
                return response()->json(['message' => 'interval_reading is required for this trigger type.'], 422);
            }
        }

        $before = $pmRule->toArray();
        $pmRule->update($validated);
        $after = $pmRule->fresh()->toArray();

        app(AuditLogger::class)->log('pm_rule.updated', $pmRule, $before, $after);

        $pmRule->load(['usageReadingType', 'createdBy']);
        $pmRule->loadCount(['assignments' => fn ($q) => $q->where('is_active', true)]);

        return (new PmRuleResource($pmRule->fresh()))->toResponse($request);
    }

    public function deactivate(PmRule $pmRule, DeactivatePmRule $action): JsonResponse
    {
        Gate::authorize('deactivate', $pmRule);

        try {
            $rule = $action->execute($pmRule, auth()->id());

            return response()->json(['message' => 'PM rule deactivated.', 'data' => $rule]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function reactivate(PmRule $pmRule, ReactivatePmRule $action): JsonResponse
    {
        Gate::authorize('reactivate', $pmRule);

        try {
            $rule = $action->execute($pmRule, auth()->id());

            return response()->json(['message' => 'PM rule reactivated.', 'data' => $rule]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function assignments(Request $request, PmRule $pmRule): JsonResponse
    {
        Gate::authorize('viewAssignments', $pmRule);

        $assignments = $pmRule->assignments()
            ->with(['asset', 'pmRule.usageReadingType', 'assignedBy'])
            ->get();

        return AssetPmAssignmentResource::collection($assignments)->toResponse($request);
    }
}
