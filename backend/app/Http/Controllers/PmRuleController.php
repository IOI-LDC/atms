<?php

namespace App\Http\Controllers;

use App\Actions\Pm\CreatePmRule;
use App\Actions\Pm\DeactivatePmRule;
use App\Actions\Pm\ReactivatePmRule;
use App\Actions\Pm\UpdatePmRule;
use App\Enums\PmTriggerType;
use App\Http\Resources\AssetPmAssignmentResource;
use App\Http\Resources\PmRuleResource;
use App\Models\PmRule;
use App\Queries\PmRules\PmRuleIndexQuery;
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

    public function store(Request $request, CreatePmRule $action): JsonResponse
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

        $rule = $action->execute($validated, $request->user()->id);

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

    public function update(Request $request, PmRule $pmRule, UpdatePmRule $action): JsonResponse
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

        $rule = $action->execute($pmRule, $validated);

        return (new PmRuleResource($rule))->toResponse($request);
    }

    public function deactivate(Request $request, PmRule $pmRule, DeactivatePmRule $action): JsonResponse
    {
        Gate::authorize('deactivate', $pmRule);

        try {
            $rule = $action->execute($pmRule, $request->user()->id);

            return response()->json(['message' => 'PM rule deactivated.', 'data' => $rule]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function reactivate(Request $request, PmRule $pmRule, ReactivatePmRule $action): JsonResponse
    {
        Gate::authorize('reactivate', $pmRule);

        try {
            $rule = $action->execute($pmRule, $request->user()->id);

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
