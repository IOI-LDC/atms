<?php

namespace App\Http\Resources;

use App\Models\Asset;
use App\Models\WorkOrder;
use App\Support\Assets\AssetConditionLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of R-1 Assets Status Report — the exact column set LDC specified,
 * flattened for a table and a CSV export.
 *
 * `assigned_to` is the technician on the asset's most recent open or in-progress
 * work order. Assets have no custodian column; this is the only person ATMS
 * associates with an asset, and it is null when nothing is open.
 *
 * @mixin Asset
 */
class AssetStatusReportItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var WorkOrder|null $openWorkOrder */
        $openWorkOrder = $this->whenLoaded('workOrders', fn () => $this->workOrders->first(), null);

        return [
            'id' => $this->id,
            'asset_tag' => $this->asset_tag,
            'name' => $this->name,
            'asset_kind' => $this->asset_kind?->value,
            'maintenance_category' => $this->whenLoaded(
                'maintenanceCategory',
                fn () => $this->maintenanceCategory?->name
            ),
            'operational_status' => $this->operational_status?->value,
            // Both, as everywhere else that renders a condition: the value is
            // what a filter round-trips on, the label is what a person reads.
            'condition_status' => $this->condition_status,
            'condition_label' => AssetConditionLabels::for($this->condition_status),
            'is_booked' => (bool) $this->is_booked,
            'location' => $this->whenLoaded(
                'currentLocation',
                fn () => $this->currentLocation?->name
            ),
            'assigned_to' => $openWorkOrder?->assignedTo?->name,
            'open_work_order_number' => $openWorkOrder?->number,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
