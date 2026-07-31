<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset->id,
                'name' => $this->asset->name,
                'asset_tag' => $this->asset->asset_tag,
            ]),
            'booked_by' => $this->whenLoaded('bookedBy', fn () => [
                'id' => $this->bookedBy->id,
                'name' => $this->bookedBy->name,
            ]),
            'booked_from' => $this->booked_from->toDateString(),
            'booked_until' => $this->booked_until->toDateString(),
            'booking_reference' => $this->booking_reference,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
