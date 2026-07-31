<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'emp_id' => $this->emp_id,
            'name' => $this->name,
            'email' => $this->email,
            'department' => $this->department,
            'job_title' => $this->job_title,
            'source_is_active' => $this->source_is_active,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'provisioned' => $this->whenLoaded('user', fn () => $this->user !== null, false),
        ];
    }
}
