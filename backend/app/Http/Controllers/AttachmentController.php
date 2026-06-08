<?php

namespace App\Http\Controllers;

use App\Actions\Attachments\SoftDeleteAttachment;
use App\Actions\Attachments\UploadAttachment;
use App\Http\Resources\AttachmentResource;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\MaintenanceRequest;
use App\Models\Part;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function indexForAsset(Request $request, Asset $asset): JsonResponse
    {
        Gate::authorize('viewForAsset', Attachment::class);

        return AttachmentResource::collection($asset->attachments()->with('uploadedBy')->get())->toResponse($request);
    }

    public function uploadForAsset(Request $request, Asset $asset, UploadAttachment $action): JsonResponse
    {
        Gate::authorize('uploadToAsset', [Attachment::class, $asset]);

        $validated = $request->validate([
            'file' => ['required', 'file'],
            'description' => ['nullable', 'string'],
        ]);

        try {
            $attachment = $action->execute(
                $validated['file'],
                Asset::class,
                $asset->id,
                auth()->id(),
                $validated['description'] ?? null,
            );

            return response()->json(['data' => $attachment], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function indexForPart(Request $request, Part $part): JsonResponse
    {
        Gate::authorize('viewForPart', Attachment::class);

        return AttachmentResource::collection($part->attachments()->with('uploadedBy')->get())->toResponse($request);
    }

    public function uploadForPart(Request $request, Part $part, UploadAttachment $action): JsonResponse
    {
        Gate::authorize('uploadToPart', [Attachment::class, $part]);

        $validated = $request->validate([
            'file' => ['required', 'file'],
            'description' => ['nullable', 'string'],
        ]);

        try {
            $attachment = $action->execute(
                $validated['file'],
                Part::class,
                $part->id,
                auth()->id(),
                $validated['description'] ?? null,
            );

            return response()->json(['data' => $attachment], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function indexForMaintenanceRequest(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        Gate::authorize('viewForMaintenanceRequest', [Attachment::class, $maintenanceRequest]);

        return AttachmentResource::collection($maintenanceRequest->attachments()->with('uploadedBy')->get())->toResponse($request);
    }

    public function uploadForMaintenanceRequest(Request $request, MaintenanceRequest $maintenanceRequest, UploadAttachment $action): JsonResponse
    {
        Gate::authorize('uploadToMaintenanceRequest', [Attachment::class, $maintenanceRequest]);

        $validated = $request->validate([
            'file' => ['required', 'file'],
            'description' => ['nullable', 'string'],
        ]);

        try {
            $attachment = $action->execute(
                $validated['file'],
                MaintenanceRequest::class,
                $maintenanceRequest->id,
                auth()->id(),
                $validated['description'] ?? null,
            );

            return response()->json(['data' => $attachment], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function indexForWorkOrder(Request $request, WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('viewForWorkOrder', Attachment::class);

        return AttachmentResource::collection($workOrder->attachments()->with('uploadedBy')->get())->toResponse($request);
    }

    public function uploadForWorkOrder(Request $request, WorkOrder $workOrder, UploadAttachment $action): JsonResponse
    {
        Gate::authorize('uploadToWorkOrder', [Attachment::class, $workOrder]);

        $validated = $request->validate([
            'file' => ['required', 'file'],
            'description' => ['nullable', 'string'],
        ]);

        try {
            $attachment = $action->execute(
                $validated['file'],
                WorkOrder::class,
                $workOrder->id,
                auth()->id(),
                $validated['description'] ?? null,
            );

            return response()->json(['data' => $attachment], 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function download(Attachment $attachment): StreamedResponse|JsonResponse
    {
        if ($attachment->deleted_at !== null) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }

        Gate::authorize('download', $attachment);

        return $attachment->streamDownload();
    }

    public function softDelete(Attachment $attachment, SoftDeleteAttachment $action): JsonResponse
    {
        if ($attachment->deleted_at !== null) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }

        Gate::authorize('delete', $attachment);

        try {
            $action->execute($attachment, auth()->id());

            return response()->json(['message' => 'Attachment deleted.']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
