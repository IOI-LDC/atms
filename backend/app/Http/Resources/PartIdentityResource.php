<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one shape every embedded part reference uses.
 *
 * Backs the shared `PartIdentity` frontend component: the name as plain text
 * followed by value-only badges for supplier Part Number, Size and Maintenance
 * Category, plus an out-of-stock badge derived from `available_quantity`.
 * A missing value yields no badge.
 *
 * `available_quantity` is the ERP snapshot, not a live ATMS balance — recording
 * consumption never decrements it. It travels here because the part picker
 * disables a zero-quantity option and `RecordWorkOrderPart` rejects one.
 *
 * `erp_part_code` is intentionally absent, for the same reason as
 * `erp_asset_code` on {@see AssetIdentityResource}.
 *
 * Requires `maintenanceCategory` to be eager-loaded.
 */
class PartIdentityResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'part_number' => $this->part_number,
            'unit_of_measure' => $this->unit_of_measure,
            'size' => $this->size_inches?->format(),
            'size_inches' => $this->size_inches?->canonical(),
            'maintenance_category' => $this->maintenanceCategory === null ? null : [
                'id' => $this->maintenanceCategory->id,
                'code' => $this->maintenanceCategory->code,
                'name' => $this->maintenanceCategory->name,
            ],
            'available_quantity' => (float) $this->available_quantity,
        ];
    }
}
