<?php

namespace App\Queries\Reports;

use App\Enums\AssetKind;
use App\Enums\OperationalStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MaintenanceCategory;
use App\Support\Size;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * R-2: asset distribution across one or more dimensions.
 *
 * Generalises the original "assets by location" report: the same aggregate —
 * asset count with an operational-status, asset-kind, and booked breakdown —
 * cut by any combination of **location**, **maintenance category**, and
 * **size**.
 *
 * Passing one dimension gives a clean per-location (or per-category, per-size)
 * summary. Passing several gives one row per distinct combination, which is
 * what makes the export a pivot-ready table:
 *
 *     Category    Size     Location   Assets
 *     Mud Motor   6 3/4"   Rig-7          12
 *     Mud Motor   6 3/4"   Yard-A          8
 *
 * All three dimensions are plain columns on `assets`, so any combination is a
 * single grouped aggregate and labels resolve in one lookup per dimension.
 * This is deliberately unlike MTBF/MTTR/Bad-Actor, which resolve their
 * dimension per row in PHP because they group *activity* records by their
 * asset's attributes rather than grouping assets directly.
 *
 * Assets missing a grouped value are never dropped — they fall into an explicit
 * null bucket ("Unassigned" / "Uncategorised" / "Unspecified") so row counts
 * always reconcile with the total.
 *
 * FA Subclass is deliberately not offered: it is ERP-owned, and ATMS reports
 * group on the fields ATMS governs.
 */
class AssetDistributionReportQuery
{
    /** Dimension => the assets column it groups on. */
    private const DIMENSIONS = [
        'location' => 'current_location_id',
        'maintenance_category' => 'maintenance_category_id',
        'size' => 'size_inches',
    ];

    /** Dimension => label for the bucket holding assets with no value. */
    private const NULL_LABELS = [
        'location' => 'Unassigned',
        'maintenance_category' => 'Uncategorised',
        'size' => 'Unspecified',
    ];

    /**
     * @param  array<int, string>  $groupBy  Ordered dimensions; column order follows it.
     * @param  array{maintenance_category_id?: ?int, asset_kind?: ?string, operational_status?: ?string, include_inactive?: bool}  $filters
     * @return array{
     *     group_by: array<int, string>,
     *     summary: array{total_assets: int, total_groups: int, total_booked: int},
     *     items: array<int, array<string, mixed>>,
     * }
     *
     * @throws InvalidArgumentException
     */
    public function handle(array $groupBy, array $filters): array
    {
        $groupBy = $this->validated($groupBy);
        $columns = array_map(fn (string $d) => self::DIMENSIONS[$d], $groupBy);

        $rows = $this->aggregate($columns, $filters);
        $labels = $this->labels($groupBy, $rows);

        $items = $rows->map(fn ($r) => $this->item($r, $groupBy, $labels))->values();

        return [
            'group_by' => $groupBy,
            'summary' => [
                'total_assets' => (int) $items->sum('asset_count'),
                // Counts only fully-identified combinations. A row with any
                // null bucket in it is visible but is not a group anyone can
                // act on — this keeps "N locations" honest when one dimension
                // is selected, exactly as it read before multi-grouping.
                'total_groups' => $items
                    ->reject(fn (array $i) => collect($i['groups'])->contains('is_unassigned', true))
                    ->count(),
                'total_booked' => (int) $items->sum('booked_count'),
            ],
            'items' => $items->all(),
        ];
    }

    /**
     * @param  array<int, string>  $groupBy
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     */
    private function validated(array $groupBy): array
    {
        // Duplicates would emit the same column twice and multiply nothing.
        $groupBy = array_values(array_unique($groupBy));

        if ($groupBy === []) {
            return ['location'];
        }

        foreach ($groupBy as $dimension) {
            if (! isset(self::DIMENSIONS[$dimension])) {
                throw new InvalidArgumentException("Unsupported asset distribution dimension [{$dimension}].");
            }
        }

        return $groupBy;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function aggregate(array $columns, array $filters): Collection
    {
        $today = now()->toDateString();

        $query = Asset::query()
            ->when($filters['maintenance_category_id'] ?? null, fn ($q, $v) => $q->where('maintenance_category_id', $v))
            ->when($filters['asset_kind'] ?? null, fn ($q, $v) => $q->where('asset_kind', $v))
            ->when($filters['operational_status'] ?? null, fn ($q, $v) => $q->where('operational_status', $v))
            ->when(! ($filters['include_inactive'] ?? false), fn ($q) => $q->where('is_active', true))
            ->selectRaw('count(*) as asset_count')
            ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as active_count', [OperationalStatus::ACTIVE->value])
            ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as under_maintenance_count', [OperationalStatus::UNDER_MAINTENANCE->value])
            ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as down_count', [OperationalStatus::DOWN->value])
            ->selectRaw('sum(case when operational_status = ? then 1 else 0 end) as inactive_count', [OperationalStatus::INACTIVE->value])
            ->selectRaw('sum(case when asset_kind = ? then 1 else 0 end) as standalone_count', [AssetKind::ASSET->value])
            ->selectRaw('sum(case when asset_kind = ? then 1 else 0 end) as package_count', [AssetKind::PACKAGE->value])
            ->selectRaw('sum(case when asset_kind = ? then 1 else 0 end) as component_count', [AssetKind::COMPONENT->value])
            ->selectRaw('sum(case when assets.id in (select asset_id from bookings where status = ? and booked_from <= ? and booked_until >= ?) then 1 else 0 end) as booked_count', ['active', $today, $today]);

        // Aliased positionally so the mapper can read them back without
        // knowing which physical column each dimension used.
        foreach ($columns as $i => $column) {
            $query->selectRaw("{$column} as group_key_{$i}")->groupByRaw($column);
            // Nulls last, then natural order. Size sorts numerically because
            // the column is numeric(9,5), not text.
            $query->orderByRaw("{$column} IS NULL, {$column}");
        }

        return $query->get();
    }

    /**
     * One lookup per dimension, keyed by position so callers can resolve a
     * row's label for dimension `i` directly.
     *
     * @param  array<int, string>  $groupBy
     * @param  Collection<int, object>  $rows
     * @return array<int, array<array-key, string>>
     */
    private function labels(array $groupBy, Collection $rows): array
    {
        $labels = [];

        foreach ($groupBy as $i => $dimension) {
            $keys = $rows->pluck("group_key_{$i}")->filter(fn ($k) => $k !== null)->unique();

            $labels[$i] = $keys->isEmpty() ? [] : match ($dimension) {
                'location' => Location::whereIn('id', $keys)->pluck('name', 'id')->all(),
                'maintenance_category' => MaintenanceCategory::whereIn('id', $keys)->pluck('name', 'id')->all(),
                // Size has no lookup table — the canonical decimal formats
                // itself into O&G notation (6.75000 => 6 3/4").
                'size' => $keys->mapWithKeys(fn ($k) => [
                    (string) $k => Size::fromCanonical((string) $k)->format(),
                ])->all(),
            };
        }

        return $labels;
    }

    /**
     * @param  array<int, string>  $groupBy
     * @param  array<int, array<array-key, string>>  $labels
     * @return array<string, mixed>
     */
    private function item(object $row, array $groupBy, array $labels): array
    {
        $groups = [];

        foreach ($groupBy as $i => $dimension) {
            $key = $row->{"group_key_{$i}"};
            $isNull = $key === null;

            $groups[] = [
                'dimension' => $dimension,
                'key' => $key,
                'label' => $isNull
                    ? self::NULL_LABELS[$dimension]
                    : ($labels[$i][$key] ?? $labels[$i][(string) $key] ?? null),
                'is_unassigned' => $isNull,
            ];
        }

        return [
            'groups' => $groups,
            'asset_count' => (int) $row->asset_count,
            'by_operational_status' => [
                'active' => (int) $row->active_count,
                'under_maintenance' => (int) $row->under_maintenance_count,
                'down' => (int) $row->down_count,
                'inactive' => (int) $row->inactive_count,
            ],
            'by_asset_kind' => [
                'standalone' => (int) $row->standalone_count,
                'package' => (int) $row->package_count,
                'component' => (int) $row->component_count,
            ],
            'booked_count' => (int) $row->booked_count,
        ];
    }
}
