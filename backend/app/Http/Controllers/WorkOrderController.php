<?php

namespace App\Http\Controllers;

use App\Actions\WorkOrders\AssignWorkOrder;
use App\Actions\WorkOrders\CancelWorkOrder;
use App\Actions\WorkOrders\CloseWorkOrder;
use App\Actions\WorkOrders\CompleteWorkOrder;
use App\Actions\WorkOrders\DeleteWorkOrderPart;
use App\Actions\WorkOrders\RecordWorkOrderPart;
use App\Actions\WorkOrders\StartWorkOrder;
use App\Actions\WorkOrders\UpdateWorkOrderExecution;
use App\Http\Resources\WorkOrderResource;
use App\Models\User;
use App\Models\WorkOrder;
use App\Queries\WorkOrders\WorkOrderIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $results = app(WorkOrderIndexQuery::class)->build($request);

        return WorkOrderResource::collection($results)->toResponse($request);
    }

    public function show(Request $request, WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('view', $workOrder);

        $workOrder->load(['asset', 'assignedTo', 'maintenanceRequest', 'assignedBy', 'parts.part', 'attachments']);

        return (new WorkOrderResource($workOrder))->toResponse($request);
    }

    public function assign(Request $request, WorkOrder $workOrder, AssignWorkOrder $action): JsonResponse
    {
        Gate::authorize('assign', $workOrder);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $assignee = User::findOrFail($validated['user_id']);

        try {
            $wo = $action->execute($workOrder, $assignee->id, auth()->id());

            return response()->json(['message' => 'Work order assigned.', 'data' => $wo]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function start(WorkOrder $workOrder, StartWorkOrder $action): JsonResponse
    {
        Gate::authorize('start', $workOrder);

        try {
            $wo = $action->execute($workOrder);

            return response()->json(['message' => 'Work order started.', 'data' => $wo]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function update(Request $request, WorkOrder $workOrder, UpdateWorkOrderExecution $action): JsonResponse
    {
        Gate::authorize('updateExecution', $workOrder);

        $validated = $request->validate([
            'description' => ['nullable', 'string'],
        ]);

        try {
            $wo = $action->execute($workOrder, $validated);

            return response()->json(['message' => 'Work order updated.', 'data' => $wo]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function complete(Request $request, WorkOrder $workOrder, CompleteWorkOrder $action): JsonResponse
    {
        Gate::authorize('complete', $workOrder);

        $validated = $request->validate([
            'completion_notes' => ['nullable', 'string'],
        ]);

        try {
            $wo = $action->execute($workOrder, auth()->id(), $validated['completion_notes'] ?? null);

            return response()->json(['message' => 'Work order completed.', 'data' => $wo]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function close(WorkOrder $workOrder, CloseWorkOrder $action): JsonResponse
    {
        Gate::authorize('close', $workOrder);

        try {
            $wo = $action->execute($workOrder, auth()->id());

            return response()->json(['message' => 'Work order closed.', 'data' => $wo]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function cancel(Request $request, WorkOrder $workOrder, CancelWorkOrder $action): JsonResponse
    {
        Gate::authorize('cancel', $workOrder);

        $validated = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        try {
            $wo = $action->execute($workOrder, auth()->id(), $validated['reason']);

            return response()->json(['message' => 'Work order cancelled.', 'data' => $wo]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function addPart(Request $request, WorkOrder $workOrder, RecordWorkOrderPart $action): JsonResponse
    {
        Gate::authorize('updateExecution', $workOrder);

        $validated = $request->validate([
            'part_id' => ['required', 'exists:parts,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $partLine = $action->execute(
                $workOrder->id,
                $validated['part_id'],
                (float) $validated['quantity'],
                auth()->id(),
                $validated['notes'] ?? null
            );

            return response()->json(['message' => 'Part added to work order.', 'data' => $partLine->load('part')], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function removePart(WorkOrder $workOrder, int $partLine, DeleteWorkOrderPart $action): JsonResponse
    {
        Gate::authorize('updateExecution', $workOrder);

        try {
            $action->execute($partLine, $workOrder->id);

            return response()->json(['message' => 'Part removed from work order.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
