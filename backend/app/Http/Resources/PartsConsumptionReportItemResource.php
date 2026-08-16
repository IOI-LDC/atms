<?php

namespace App\Http\Resources;

use App\Support\Size;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One grouped Parts Consumption row.
 *
 * The part is serialized in the same shape as {@see PartIdentityResource}
 * (no ERP part code) and each row carries the Asset Maintenance Category and
 * Asset Size dimensions it was aggregated under. Do not confuse
 * `asset_maintenance_category` (the asset's) with `part.maintenance_category`
 * (the part's) — a row carries both. `asset_size_key` is the non-null
 * canonical sort key produced by the query; a missing size surfaces as
 * `Unspecified` / null.
 */
class PartsConsumptionReportItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $partSize = $this->part_size_inches !== null
            ? Size::fromCanonical((string) $this->part_size_inches)
            : null;

        $hasAssetSize = $this->asset_size_key !== 'unspecified';

        return [
            'part_id' => (int) $this->part_id,
            'part' => [
                'id' => (int) $this->part_id,
                'name' => $this->part_name,
                'erp_part_code' => $this->erp_part_code,
                'part_number' => $this->part_number,
                'unit_of_measure' => $this->unit_of_measure,
                'size' => $partSize?->format(),
                'size_inches' => $partSize?->canonical(),
                'maintenance_category' => $this->part_category_code === null ? null : [
                    'id' => (int) $this->part_maintenance_category_id,
                    'code' => $this->part_category_code,
                    'name' => $this->part_category_name,
                ],
                'available_quantity' => (float) $this->available_quantity,
            ],
            'asset_maintenance_category' => $this->asset_maintenance_category,
            'asset_size' => $hasAssetSize ? Size::fromCanonical($this->asset_size_key)->format() : 'Unspecified',
            'asset_size_inches' => $hasAssetSize ? $this->asset_size_key : null,
            'total_quantity' => (float) $this->total_quantity,
            'line_item_count' => (int) $this->line_item_count,
            'work_order_count' => (int) $this->work_order_count,
        ];
    }
}
