<?php

namespace App\Queries\Reports;

use App\Enums\AssetKind;
use App\Enums\OperationalStatus;
use App\Models\Asset;
use App\Models\Location;
use Illuminate\Support\Collection;

/**
 * R-2: asset distribution by location. One row per location plus an
 * "Unassigned" bucket for assets with no current_location_id. Each row
 * breaks down by operational_status, asset_kind and booked count.
 */
class AssetsByLocationReportQuery
{
    /**
     * @param  array{fa_subclass_code?: ?string, asset_kind?: ?string, operational_status?: ?string, include_inactive?: bool}  $filters
     * @return array{summary: array{total_assets: int, total_locations: int, total_booked: int}, items: array<int, array<string, mixed>>}
     */
    public function handle(array $filters): array
    {
        $base = Asset::query()
            ->when($filters['fa_subclass_code'] ?? null, fn ($q, $v) => $q->where('fa_subclass_code', $v))
            ->when($filters['asset_kind'] ?? null, fn ($q, $v) => $q->where('asset_kind', $v))
            ->when($filters['operational_status'] ?? null, fn ($q, $v) => $q->where('operational_status', $v))
            ->when(! ($filters['include_inactive'] ?? false), fn ($q) => $q->where('is_active', true));

        $rows = (clone $base)
            ->selectRaw('current_location_id, count(*) as asset_count')
            ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as active_count', [OperationalStatus::ACTIVE->value])
            ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as under_maintenance_count', [OperationalStatus::UNDER_MAINTENANCE->value])
            ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as down_count', [OperationalStatus::DOWN->value])
            ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as inactive_count', [OperationalStatus::INACTIVE->value])
            ->selectRaw('sum(case when asset_kind = ? then 1 else 0 end) as standalone_count', [AssetKind::ASSET->value])
            ->selectRaw('sum(case when asset_kind = ? then 1 else 0 end) as package_count', [AssetKind::PACKAGE->value])
            ->selectRaw('sum(case when asset_kind = ? then 1 else 0 end) as component_count', [AssetKind::COMPONENT->value])
            ->selectRaw('sum(case when is_booked = true then 1 else 0 end) as booked_count')
            ->groupBy('current_location_id')
            ->orderByRaw('current_location_id IS NULL, current_location_id')
            ->get();

        $locationNames = Location::whereIn('id', $rows->pluck('current_location_id')->filter())
            ->pluck('name', 'id');

        $items = $rows->map(function ($r) use ($locationNames) {
            $unassigned = $r->current_location_id === null;

            return [
                'location_id' => $r->current_location_id,
                'location_name' => $unassigned ? 'Unassigned' : ($locationNames[$r->current_location_id] ?? null),
                'is_unassigned' => $unassigned,
                'asset_count' => (int) $r->asset_count,
                'by_operational_status' => [
                    'active' => (int) $r->active_count,
                    'under_maintenance' => (int) $r->under_maintenance_count,
                    'down' => (int) $r->down_count,
                    'inactive' => (int) $r->inactive_count,
                ],
                'by_asset_kind' => [
                    'standalone' => (int) $r->standalone_count,
                    'package' => (int) $r->package_count,
                    'component' => (int) $r->component_count,
                ],
                'booked_count' => (int) $r->booked_count,
            ];
        })->values();

        return [
            'summary' => [
                'total_assets' => (int) $items->sum('asset_count'),
                'total_locations' => (int) $items->filter(fn ($i) => ! $i['is_unassigned'])->count(),
                'total_booked' => (int) $items->sum('booked_count'),
            ],
            'items' => $items->all(),
        ];
    }
}
