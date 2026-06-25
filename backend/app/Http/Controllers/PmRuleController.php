<?php

namespace App\Http\Controllers;

use App\Actions\Pm\DeactivatePmRule;
use App\Actions\Pm\EvaluatePmRule;
use App\Actions\Pm\ReactivatePmRule;
use App\Enums\PmTriggerType;
use App\Http\Resources\PmRuleResource;
use App\Models\Asset;
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
            'asset_id' => ['required', 'exists:assets,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trigger_type' => ['required', 'string', 'in:date,reading,date_or_reading'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'required_if:trigger_type,date,date_or_reading'],
            'interval_reading' => ['nullable', 'numeric', 'min:0.01', 'required_if:trigger_type,reading,date_or_reading'],
            'usage_reading_type_id' => ['nullable', 'exists:usage_reading_types,id', 'required_if:trigger_type,reading,date_or_reading'],
        ]);

        $asset = Asset::findOrFail($validated['asset_id']);

        $rule = PmRule::create([
            'asset_id' => $validated['asset_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'trigger_type' => $validated['trigger_type'],
            'interval_days' => $validated['interval_days'] ?? null,
            'interval_reading' => $validated['interval_reading'] ?? null,
            'usage_reading_type_id' => $validated['usage_reading_type_id'] ?? null,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        app(AuditLogger::class)->log('pm_rule.created', $rule, [], $rule->toArray());

        return response()->json(['data' => $rule], 201);
    }

    public function show(Request $request, PmRule $pmRule): JsonResponse
    {
        Gate::authorize('view', $pmRule);

        $pmRule->load(['asset', 'usageReadingType', 'createdBy', 'suppressions']);

        return (new PmRuleResource($pmRule))->toResponse($request);
    }

    public function update(Request $request, PmRule $pmRule): JsonResponse
    {
        Gate::authorize('update', $pmRule);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
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

        return response()->json(['data' => $pmRule->fresh()]);
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

    public function evaluate(PmRule $pmRule, EvaluatePmRule $action): JsonResponse
    {
        Gate::authorize('evaluate', $pmRule);

        try {
            $mr = $action->execute($pmRule, auth()->id());

            if ($mr === null) {
                return response()->json(['message' => 'PM rule is not due.']);
            }

            return response()->json(['message' => 'PM request generated.', 'data' => $mr], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function evaluateAll(Request $request, EvaluatePmRule $action): JsonResponse
    {
        Gate::authorize('evaluate', PmRule::class);

        $rules = PmRule::where('is_active', true)->get();
        $generated = 0;

        foreach ($rules as $rule) {
            try {
                $mr = $action->execute($rule, auth()->id());
                if ($mr !== null) {
                    $generated++;
                }
            } catch (\DomainException $e) {
                continue;
            }
        }

        return response()->json(['message' => "Evaluated {$rules->count()} rules, generated {$generated} requests."]);
    }
}
