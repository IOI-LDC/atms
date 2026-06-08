<?php

namespace App\Http\Controllers;

use App\Actions\MaintenanceRequests\ApproveMaintenanceRequestAndCreateWorkOrder;
use App\Actions\MaintenanceRequests\CancelMaintenanceRequest;
use App\Actions\MaintenanceRequests\CreateCorrectiveMaintenanceRequest;
use App\Actions\MaintenanceRequests\RejectMaintenanceRequest;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\MaintenanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MaintenanceRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MaintenanceRequest::class);

        $user = $request->user();

        $query = MaintenanceRequest::with(['asset', 'createdBy']);

        if ($user->hasRole(RoleCode::REQUESTER)) {
            $query->where('created_by', $user->id);
        }

        return response()->json(['data' => $query->orderByDesc('created_at')->get()]);
    }

    public function show(MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        Gate::authorize('view', $maintenanceRequest);

        return response()->json(['data' => $maintenanceRequest->load(['asset', 'createdBy', 'reviewedBy', 'workOrder'])]);
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

        $validated = $request->validate([
            'reason' => ['required', 'string'],
            'suppressed_until_date' => ['nullable', 'date'],
            'suppressed_until_reading' => ['nullable', 'numeric'],
        ]);

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

        $validated = $request->validate([
            'reason' => ['required', 'string'],
            'suppressed_until_date' => ['nullable', 'date'],
            'suppressed_until_reading' => ['nullable', 'numeric'],
        ]);

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
