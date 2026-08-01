<?php

namespace App\Queries\Reports;

use App\Http\Resources\AssetIdentityResource;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\MaintenanceCategory;
use App\Models\UsageReadingType;
use App\Support\Size;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * R-22: Most-Used Assets — which assets did the most work in a period.
 *
 * Ranks assets by accumulated usage against **one** usage reading type
 * (Operating Hours, Kilometer Driven, or Depth), optionally rolled up by
 * maintenance category or size.
 *
 * ## Why one reading type at a time
 *
 * Hours, kilometres, and metres are different units. Summing or ranking across
 * them would be meaningless, so the reading type is a required dimension of the
 * question, not an optional filter. The unit travels with the response so the
 * UI never has to guess how to label a number.
 *
 * ## How usage is measured
 *
 * These meters are cumulative (odometer-style), so usage is a *difference*, not
 * a sum of readings:
 *
 *     usage = end_value - baseline_value
 *
 * - `end_value` — the highest confirmed reading at or before `to`.
 * - `baseline_value` — the highest confirmed reading *before* `from`; when the
 *   asset has no reading before the window (a newly-metered asset), it falls
 *   back to the lowest confirmed reading inside the window.
 *
 * Taking the baseline from *before* the window is what makes the number
 * correct. A naive max-minus-min over readings inside the window reports zero
 * usage for any asset with a single reading in range, which is exactly the
 * busy-asset case: one reading in, one reading out is the norm for equipment
 * that goes out on a job and comes back.
 *
 * Only **confirmed** readings count (`confirmed_at is not null`), matching
 * PmDueCalculator — an unconfirmed reading is a claim, not a fact. Soft-deleted
 * readings are excluded by the model's SoftDeletes trait.
 *
 * Meters are assumed monotonic. A physical meter replacement or a downward
 * correction would understate usage; ATMS has no meter-reset concept to detect
 * that, so it is a known limitation rather than a bug.
 */
class AssetUsageReportQuery
{
    /** Dimensions this report can roll up to, beyond the per-asset default. */
    private const GROUPINGS = ['asset', 'maintenance_category', 'size'];

    /**
     * @param  array{limit?: ?int, location_id?: ?int, maintenance_category_id?: ?int}  $filters
     * @return array{
     *     reading_type: array{id: int, name: string, unit: ?string},
     *     group_by: string,
     *     summary: array{total_usage: float, assets_with_usage: int, unit: ?string},
     *     items: array<int, array<string, mixed>>,
     * }
     *
     * @throws InvalidArgumentException
     */
    public function handle(
        UsageReadingType $readingType,
        ?Carbon $from,
        Carbon $to,
        string $groupBy,
        array $filters,
    ): array {
        if (! in_array($groupBy, self::GROUPINGS, true)) {
            throw new InvalidArgumentException("Unsupported asset usage dimension [{$groupBy}].");
        }

        $assetIds = $this->eligibleAssetIds($filters);

        $perAsset = $assetIds->isEmpty()
            ? collect()
            : $this->usagePerAsset($readingType->id, $assetIds, $from, $to);

        $items = $groupBy === 'asset'
            ? $this->assetItems($perAsset, (int) ($filters['limit'] ?? 25))
            : $this->groupedItems($perAsset, $groupBy, (int) ($filters['limit'] ?? 25));

        return [
            'reading_type' => [
                'id' => $readingType->id,
                'name' => $readingType->name,
                'unit' => $readingType->unit,
            ],
            'group_by' => $groupBy,
            'summary' => [
                // Summary spans every eligible asset, not just the top N shown.
                'total_usage' => round((float) $perAsset->sum('usage'), 2),
                'assets_with_usage' => $perAsset->filter(fn ($r) => $r['usage'] > 0)->count(),
                'unit' => $readingType->unit,
            ],
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, int>
     */
    private function eligibleAssetIds(array $filters): Collection
    {
        return Asset::query()
            ->where('is_active', true)
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('current_location_id', $v))
            ->when($filters['maintenance_category_id'] ?? null, fn ($q, $v) => $q->where('maintenance_category_id', $v))
            ->pluck('id');
    }

    /**
     * Per-asset usage, computed with three grouped aggregates rather than a
     * query per asset, so cost stays flat as the register grows.
     *
     * @param  Collection<int, int>  $assetIds
     * @return Collection<int, array<string, mixed>>
     */
    private function usagePerAsset(int $readingTypeId, Collection $assetIds, ?Carbon $from, Carbon $to): Collection
    {
        $confirmed = fn () => AssetMeterReading::query()
            ->whereNotNull('confirmed_at')
            ->where('usage_reading_type_id', $readingTypeId)
            ->whereIn('asset_id', $assetIds);

        // End state: where the meter stood at the close of the window.
        $end = $confirmed()
            ->where('reading_at', '<=', $to)
            ->selectRaw('asset_id, max(reading_value) as end_value, max(reading_at) as last_at, count(*) as reading_count')
            ->groupBy('asset_id')
            ->get()
            ->keyBy('asset_id');

        // Baseline: where the meter stood entering the window.
        $baseline = $from === null
            ? collect()
            : $confirmed()
                ->where('reading_at', '<', $from)
                ->selectRaw('asset_id, max(reading_value) as baseline_value')
                ->groupBy('asset_id')
                ->get()
                ->keyBy('asset_id');

        // Fallback baseline for assets first metered inside the window.
        $windowFloor = $confirmed()
            ->when($from !== null, fn ($q) => $q->where('reading_at', '>=', $from))
            ->where('reading_at', '<=', $to)
            ->selectRaw('asset_id, min(reading_value) as floor_value')
            ->groupBy('asset_id')
            ->get()
            ->keyBy('asset_id');

        return $end->map(function ($row, $assetId) use ($baseline, $windowFloor) {
            $start = $baseline->get($assetId)?->baseline_value
                ?? $windowFloor->get($assetId)?->floor_value
                ?? $row->end_value;

            return [
                'asset_id' => (int) $assetId,
                'usage' => round(max(0, (float) $row->end_value - (float) $start), 2),
                'latest_reading' => round((float) $row->end_value, 2),
                'last_reading_at' => $row->last_at,
                'reading_count' => (int) $row->reading_count,
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $perAsset
     * @return array<int, array<string, mixed>>
     */
    private function assetItems(Collection $perAsset, int $limit): array
    {
        $ranked = $perAsset->sortByDesc('usage')->take($limit)->values();

        $assets = Asset::whereIn('id', $ranked->pluck('asset_id'))
            ->with(['maintenanceCategory', 'currentLocation'])
            ->get()
            ->keyBy('id');

        return $ranked->map(function (array $row) use ($assets) {
            $asset = $assets->get($row['asset_id']);

            return [
                'group_key' => $row['asset_id'],
                'group_label' => $asset?->name,
                'usage' => $row['usage'],
                'asset_count' => 1,
                'reading_count' => $row['reading_count'],
                'latest_reading' => $row['latest_reading'],
                'last_reading_at' => $row['last_reading_at']
                    ? Carbon::parse($row['last_reading_at'])->toIso8601String()
                    : null,
                'asset' => $asset ? (new AssetIdentityResource($asset))->toArray(request()) : null,
            ];
        })->all();
    }

    /**
     * Roll per-asset usage up to a category or size bucket.
     *
     * @param  Collection<int, array<string, mixed>>  $perAsset
     * @return array<int, array<string, mixed>>
     */
    private function groupedItems(Collection $perAsset, string $groupBy, int $limit): array
    {
        $column = $groupBy === 'size' ? 'size_inches' : 'maintenance_category_id';

        $keysByAsset = Asset::whereIn('id', $perAsset->pluck('asset_id'))
            ->pluck($column, 'id');

        $labels = $this->labelsFor($groupBy, $keysByAsset->filter(fn ($k) => $k !== null)->unique());

        return $perAsset
            ->groupBy(fn (array $row) => $keysByAsset[$row['asset_id']] ?? '__none__')
            ->map(function (Collection $group, $key) use ($groupBy, $labels) {
                $isNull = $key === '__none__';

                return [
                    'group_key' => $isNull ? null : $key,
                    'group_label' => $isNull
                        ? ($groupBy === 'size' ? 'Unspecified' : 'Uncategorised')
                        : ($labels[$key] ?? null),
                    'is_unassigned' => $isNull,
                    'usage' => round((float) $group->sum('usage'), 2),
                    'asset_count' => $group->count(),
                    'reading_count' => (int) $group->sum('reading_count'),
                ];
            })
            ->sortByDesc('usage')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $keys
     * @return array<array-key, string>
     */
    private function labelsFor(string $groupBy, Collection $keys): array
    {
        if ($keys->isEmpty()) {
            return [];
        }

        return $groupBy === 'size'
            ? $keys->mapWithKeys(fn ($k) => [(string) $k => Size::fromCanonical((string) $k)->format()])->all()
            : MaintenanceCategory::whereIn('id', $keys)->pluck('name', 'id')->all();
    }
}
