<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one shape every embedded part reference uses.
 *
 * Backs the shared `PartIdentity` frontend component: the name as plain text
 * followed by value-only badges for the ERP part code, supplier Part Number,
 * Size and Maintenance Category, plus an out-of-stock badge derived from
 * `available_quantity`. A missing value yields no badge.
 *
 * `available_quantity` is a live balance: recording a part on a work order
 * decrements it and removing the line restores it (Q6, 2026-08-16). ERP remains
 * the authority and `SyncParts` still overwrites the column wholesale, so treat
 * this as accurate between refreshes rather than as a ledger. It travels here
 * because the part picker disables a zero-quantity option and
 * `RecordWorkOrderPart` rejects one.
 *
 * `erp_part_code` travels here too (RQ4, 2026-08-16). It was previously
 * Admin-only and deliberately withheld, on the same reasoning as
 * `erp_asset_code` on {@see AssetIdentityResource} — but the two are not
 * alike in practice: the asset code is an internal ERP key, while the part
 * code is the "No." column LDC's Maintenance team works from and quotes to
 * the warehouse. Withholding it made the picker harder to use, not safer.
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
            'erp_part_code' => $this->erp_part_code,
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
