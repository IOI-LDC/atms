<?php

namespace App\Http\Controllers;

use App\Actions\MaintenanceRequests\ApproveMaintenanceRequestAndCreateWorkOrder;
use App\Actions\MaintenanceRequests\CancelMaintenanceRequest;
use App\Actions\MaintenanceRequests\CreateCorrectiveMaintenanceRequest;
use App\Actions\MaintenanceRequests\RejectMaintenanceRequest;
use App\Actions\MaintenanceRequests\UpdateMaintenanceRequest;
use App\Http\Resources\MaintenanceRequestResource;
use App\Models\Asset;
use App\Models\MaintenanceRequest;
use App\Queries\MaintenanceRequests\MaintenanceRequestIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MaintenanceRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MaintenanceRequest::class);

        $results = app(MaintenanceRequestIndexQuery::class)->build($request);

        return MaintenanceRequestResource::collection($results)->toResponse($request);
    }

    public function show(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        Gate::authorize('view', $maintenanceRequest);

        $maintenanceRequest->load(['asset', 'createdBy', 'reviewedBy', 'workOrder', 'attachments']);

        return (new MaintenanceRequestResource($maintenanceRequest))->toResponse($request);
    }

    public function update(Request $request, MaintenanceRequest $maintenanceRequest, UpdateMaintenanceRequest $action): JsonResponse
    {
        Gate::authorize('update', $maintenanceRequest);

        $validated = $request->validate([
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'asset_id' => ['nullable', 'exists:assets,id'],
        ]);

        try {
            $mr = $action->execute($maintenanceRequest, $validated);

            $mr->load(['asset', 'createdBy', 'workOrder']);

            return (new MaintenanceRequestResource($mr))->toResponse($request);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function storeCorrective(Request $request, CreateCorrectiveMaintenanceRequest $action): JsonResponse
    {
        Gate::authorize('create', MaintenanceRequest::class);

        $validated = $request->validate([
            'asset_id' => ['required', 'exists:assets,id'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', 'string', 'in:low,medium,high,critical'],
            'meter_reading' => ['nullable', 'array', 'min:1'],
            'meter_reading.usage_reading_type_id' => ['required_with:meter_reading', 'exists:usage_reading_types,id'],
            'meter_reading.reading_value' => ['required_with:meter_reading', 'numeric'],
            'meter_reading.reading_at' => ['required_with:meter_reading', 'date'],
        ]);

        $mr = $action->execute(
            Asset::findOrFail($validated['asset_id']),
            auth()->id(),
            $validated['priority'],
            $validated['description'] ?? null,
            isset($validated['meter_reading']) ? $validated['meter_reading'] : null
        );

        return response()->json(['data' => $mr->fresh()], 201);
    }

    public function approve(MaintenanceRequest $maintenanceRequest, ApproveMaintenanceRequestAndCreateWorkOrder $action): JsonResponse
    {
        Gate::authorize('approve', $maintenanceRequest);

        try {
            $mr = $action->execute($maintenanceRequest, auth()->id());

            return response()->json([
                'message' => 'Maintenance request approved and work order created.',
                'data' => $mr->load('workOrder'),
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function reject(Request $request, MaintenanceRequest $maintenanceRequest, RejectMaintenanceRequest $action): JsonResponse
    {
        Gate::authorize('reject', $maintenanceRequest);

        $rules = [
            'reason' => ['required', 'string'],
            'suppressed_until_date' => ['nullable', 'date'],
            'suppressed_until_reading' => ['nullable', 'numeric'],
        ];

        if ($maintenanceRequest->is_preventive && $maintenanceRequest->triggered_by_date) {
            $rules['suppressed_until_date'] = ['required', 'date'];
        }

        if ($maintenanceRequest->is_preventive && $maintenanceRequest->triggered_by_reading) {
            $rules['suppressed_until_reading'] = ['required', 'numeric'];
        }

        $validated = $request->validate($rules);

        try {
            $mr = $action->execute(
                $maintenanceRequest,
                auth()->id(),
                $validated['reason'],
                $validated['suppressed_until_date'] ?? null,
                $validated['suppressed_until_reading'] ?? null
            );

            return response()->json(['message' => 'Maintenance request rejected.', 'data' => $mr]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function cancel(Request $request, MaintenanceRequest $maintenanceRequest, CancelMaintenanceRequest $action): JsonResponse
    {
        Gate::authorize('cancel', $maintenanceRequest);

        $rules = [
            'reason' => ['required', 'string'],
            'suppressed_until_date' => ['nullable', 'date'],
            'suppressed_until_reading' => ['nullable', 'numeric'],
        ];

        if ($maintenanceRequest->is_preventive && $maintenanceRequest->triggered_by_date) {
            $rules['suppressed_until_date'] = ['required', 'date'];
        }

        if ($maintenanceRequest->is_preventive && $maintenanceRequest->triggered_by_reading) {
            $rules['suppressed_until_reading'] = ['required', 'numeric'];
        }

        $validated = $request->validate($rules);

        try {
            $mr = $action->execute(
                $maintenanceRequest,
                auth()->id(),
                $validated['reason'],
                $validated['suppressed_until_date'] ?? null,
                $validated['suppressed_until_reading'] ?? null
            );

            return response()->json(['message' => 'Maintenance request cancelled.', 'data' => $mr]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
