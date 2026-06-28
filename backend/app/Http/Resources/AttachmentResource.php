<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);

        $data = [
            'id' => $this->id,
            'file_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
            'download_url' => url("/api/attachments/{$this->id}/download"),
            // Policy-driven delete permission for every role. Reuses
            // AttachmentPolicy::delete as the single source of truth. The
            // non-admin path reads $attachment->attachable, so attachment list
            // endpoints MUST eager-load 'attachable' to avoid an N+1.
            'can_delete' => $user?->can('delete', $this->resource) ?? false,
        ];

        if ($isAdmin || $isManager) {
            $data['uploaded_by'] = $this->whenLoaded('uploadedBy', fn () => [
                'id' => $this->uploadedBy?->id,
                'name' => $this->uploadedBy?->name,
            ]);
        }

        return $data;
    }
}
