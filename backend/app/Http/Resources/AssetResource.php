<?php

namespace App\Http\Resources;

use App\Enums\RoleCode;
use App\Models\MasterDataItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    private const CONDITION_LABEL_CACHE = 'atms.asset_condition_labels';

    /**
     * Display label for the asset's condition, or null when it has none.
     *
     * Unknown values fall back to the raw string rather than null: a condition
     * an Admin has since deleted still tells the reader more than a blank cell.
     */
    private function conditionLabel(): ?string
    {
        if ($this->condition_status === null) {
            return null;
        }

        // Memoised on the container, which is rebuilt per request and per test,
        // so a renamed label never leaks into the next one. Resolving this per
        // row would cost one query per asset on a list of several hundred.
        if (! app()->bound(self::CONDITION_LABEL_CACHE)) {
            app()->instance(self::CONDITION_LABEL_CACHE, MasterDataItem::query()
                ->where('group_key', MasterDataItem::ASSET_CONDITIONS)
                ->pluck('label', 'value')
                ->all());
        }

        return app(self::CONDITION_LABEL_CACHE)[$this->condition_status] ?? $this->condition_status;
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user->hasRole(RoleCode::ADMINISTRATOR);
        $isManager = $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
        $isRequester = $user->hasRole(RoleCode::REQUESTER);

        $data = [
            'id' => $this->id,
            // The ERP FA subclass, exposed under its display name. It is Asset
            // Class — a separate concept from Maintenance Category below.
            'asset_class' => $this->fa_subclass_code,
            'fa_subclass_code' => $this->fa_subclass_code,
            'name' => $this->name,
            'description' => $this->description,
            'serial_number' => $this->serial_number,
            'size' => $this->size_inches?->format(),
            'size_inches' => $this->size_inches?->canonical(),
            'maintenance_category' => $this->whenLoaded('maintenanceCategory', fn () => [
                'id' => $this->maintenanceCategory?->id,
                'code' => $this->maintenanceCategory?->code,
                'name' => $this->maintenanceCategory?->name,
            ]),
            'model' => $this->model,
            'manufacturer' => $this->manufacturer,
            'operational_status' => $this->operational_status,
            'operational_status_label' => $this->operational_status?->label(),
            // The hand-set cause vocabulary. `condition_label` is resolved from
            // `asset_conditions` so a renamed label reaches every screen at once;
            // it falls back to the raw value for a condition that has since been
            // deleted, which is readable rather than blank.
            'condition_status' => $this->condition_status,
            'condition_label' => $this->conditionLabel(),
            'maintenance_status' => $this->maintenance_status?->value,
            'asset_kind' => $this->asset_kind?->value,
            'is_booked' => $this->is_booked,
            'asset_tag' => $this->asset_tag,
            'parent_asset_id' => $this->parent_asset_id,
            'child_assets_count' => $this->whenCounted('childAssets'),
            'current_location' => $this->whenLoaded('currentLocation', fn () => [
                'id' => $this->currentLocation?->id,
                'name' => $this->currentLocation?->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (! $isRequester) {
            // `erp_status` was dropped here in 4b — it duplicated
            // `maintenance_status` from the ERP's vocabulary and nothing read it.
            // The column itself goes in 4c.
            $data['erp_last_synced_at'] = $this->erp_last_synced_at?->toIso8601String();
        }

        if ($isAdmin || $isManager) {
            $data['is_active'] = $this->is_active;
        }

        // ERP identifiers are Admin-only, for integration troubleshooting. They
        // must never reach ordinary lists, dropdowns, cards, reports or printed
        // forms — hence their absence from AssetIdentityResource.
        if ($isAdmin) {
            $data['erp_asset_code'] = $this->erp_asset_code;
            $data['erp_raw_data'] = $this->erp_raw_data;
        }

        return $data;
    }
}
