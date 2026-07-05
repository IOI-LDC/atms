<?php

namespace App\Http\Controllers;

use App\Actions\WorkOrders\AssignWorkOrder;
use App\Actions\WorkOrders\CancelWorkOrder;
use App\Actions\WorkOrders\CloseWorkOrder;
use App\Actions\WorkOrders\CompleteWorkOrder;
use App\Actions\WorkOrders\DeferWorkOrderFormSync;
use App\Actions\WorkOrders\DeleteWorkOrderPart;
use App\Actions\WorkOrders\RecordWorkOrderPart;
use App\Actions\WorkOrders\SetWorkOrderAssetStatus;
use App\Actions\WorkOrders\StartWorkOrder;
use App\Actions\WorkOrders\SyncWorkOrderFormToLatest;
use App\Actions\WorkOrders\UpdateWorkOrderExecution;
use App\Actions\WorkOrders\UpdateWorkOrderFormFieldValue;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Exceptions\WorkOrderFormIncompleteException;
use App\Http\Resources\AssetResource;
use App\Http\Resources\WorkOrderFormResource;
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

        // Only load the (relatively heavy) form relations for roles that can
        // see the form — avoids 2-3 wasted queries for Logistics/Requester,
        // matching the Resource's $canSeeForm gate.
        $loads = ['asset', 'assignedTo', 'maintenanceRequest', 'assignedBy', 'parts.part', 'attachments'];
        $canSeeForm = $request->user()->hasRole(RoleCode::ADMINISTRATOR)
            || $request->user()->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $request->user()->hasRole(RoleCode::TECHNICIAN);
        if ($canSeeForm) {
            $loads[] = 'workOrderForm.fields';
            $loads[] = 'workOrderForm.template';
        }
        $workOrder->load($loads);

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
            $wo = $action->execute($workOrder, $assignee->id, $request->user()->id);

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
            $wo = $action->execute($workOrder, $request->user()->id, $validated['completion_notes'] ?? null);

            return response()->json(['message' => 'Work order completed.', 'data' => $wo]);
        } catch (WorkOrderFormIncompleteException $e) {
            return response()->json([
                'message' => 'Required WO Form fields are unfilled.',
                'missing' => $e->missing(),
            ], 422);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function close(Request $request, WorkOrder $workOrder, CloseWorkOrder $action): JsonResponse
    {
        Gate::authorize('close', $workOrder);

        // Optional ground-truth override: on close the manager may revise the
        // MR's is_failure after inspecting the asset. Absent = keep existing value.
        $validated = $request->validate([
            'is_failure' => ['nullable', 'boolean'],
        ]);

        try {
            $wo = $action->execute(
                $workOrder,
                $request->user()->id,
                array_key_exists('is_failure', $validated) ? (bool) $validated['is_failure'] : null
            );

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
            'asset_status' => ['nullable', 'in:down,active'],
        ]);

        $assetStatus = isset($validated['asset_status'])
            ? OperationalStatus::from($validated['asset_status'])
            : null;

        try {
            $wo = $action->execute($workOrder, $request->user()->id, $validated['reason'], $assetStatus);

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
                $request->user()->id,
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

    public function setAssetStatus(Request $request, WorkOrder $workOrder, SetWorkOrderAssetStatus $action): JsonResponse
    {
        Gate::authorize('setAssetStatus', $workOrder);

        $validated = $request->validate([
            'operational_status' => ['required', 'string', 'in:'.implode(',', array_map(fn ($c) => $c->value, OperationalStatus::cases()))],
        ]);

        if (in_array($workOrder->status, [WorkOrderStatus::CLOSED, WorkOrderStatus::CANCELLED], true)) {
            return response()->json([
                'message' => 'Cannot update asset status on a closed or cancelled work order.',
            ], 409);
        }

        if (! $workOrder->asset) {
            return response()->json([
                'message' => 'Work order has no associated asset.',
            ], 422);
        }

        $action->execute($workOrder, OperationalStatus::from($validated['operational_status']));

        $resource = new AssetResource($workOrder->asset->fresh()->load('currentLocation'));

        return response()->json([
            'message' => 'Asset status updated.',
            'data' => $resource->toArray($request),
        ]);
    }

    public function showForm(Request $request, WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('viewForm', $workOrder);

        $workOrder->load(['workOrderForm.fields', 'workOrderForm.template.fields']);

        if (! $workOrder->workOrderForm) {
            return response()->json(['message' => 'This work order has no attached form.'], 404);
        }

        return (new WorkOrderFormResource($workOrder->workOrderForm))->toResponse($request);
    }

    public function updateFormField(Request $request, WorkOrder $workOrder, int $field, UpdateWorkOrderFormFieldValue $action): JsonResponse
    {
        Gate::authorize('updateExecution', $workOrder);

        $validated = $request->validate([
            'pre_value' => ['sometimes', 'nullable', 'string'],
            'post_value' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        try {
            $updated = $action->execute($workOrder, $field, $validated, $request->user()->id);

            return response()->json(['message' => 'Form field value updated.', 'data' => $updated->load('workOrderForm')], 200);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function syncForm(Request $request, WorkOrder $workOrder, SyncWorkOrderFormToLatest $action): JsonResponse
    {
        Gate::authorize('updateExecution', $workOrder);

        try {
            $form = $action->execute($workOrder, $request->user()->id);

            return (new WorkOrderFormResource($form))->toResponse($request);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function deferFormSync(Request $request, WorkOrder $workOrder, DeferWorkOrderFormSync $action): JsonResponse
    {
        Gate::authorize('updateExecution', $workOrder);

        try {
            $form = $action->execute($workOrder, $request->user()->id);

            return (new WorkOrderFormResource($form))->toResponse($request);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
