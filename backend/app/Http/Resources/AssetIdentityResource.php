<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one shape every embedded asset reference uses.
 *
 * Backs the shared `AssetIdentity` frontend component, which renders the name
 * as plain text followed by value-only badges for Serial Number, Size and
 * Maintenance Category. A missing value yields no badge, so nulls here are
 * expected rather than exceptional.
 *
 * `asset_tag` is deliberately *not* one of the badges — it renders as secondary
 * text or its own column — but it travels with the identity because every
 * screen showing an asset may need it, and it must never fall back to
 * `erp_asset_code`.
 *
 * `erp_asset_code` is intentionally absent. It stays stored and is exposed only
 * on the full Admin asset payload for integration troubleshooting; it must not
 * reach lists, dropdowns, cards, reports or printed forms.
 *
 * Requires `maintenanceCategory` to be eager-loaded — see the queries feeding
 * each consumer.
 */
class AssetIdentityResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'asset_tag' => $this->asset_tag,
            'serial_number' => $this->serial_number,
            'size' => $this->size_inches?->format(),
            'size_inches' => $this->size_inches?->canonical(),
            'maintenance_category' => $this->maintenanceCategory === null ? null : [
                'id' => $this->maintenanceCategory->id,
                'code' => $this->maintenanceCategory->code,
                'name' => $this->maintenanceCategory->name,
            ],
        ];
    }
}
