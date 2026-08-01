<?php

namespace App\Services\Pm;

use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\PmOccurrenceSuppression;
use Illuminate\Support\Collection;

/**
 * The meter readings and suppressions a batch of PM assignments needs, loaded
 * in a fixed number of queries instead of a handful per assignment.
 *
 * `PmDueCalculator` has always accepted these two collections; nothing built
 * them, so every assignment fell back to its own queries — the reason a daily
 * evaluation of ~1,600 assignments could not finish inside the job timeout.
 *
 * ## Key shapes are a contract with PmDueCalculator
 *
 * - `readings`  — `"{asset_id}_{usage_reading_type_id}"` → the latest confirmed
 *   `AssetMeterReading` for that pair.
 * - `suppressions` — `"{assignment_id}_date"` / `"{assignment_id}_reading"` →
 *   a collection of plain arrays.
 *
 * Suppression rows are flattened to arrays on purpose: the calculator compares
 * `suppressed_until_date` against `now()->toDateString()`, and the model casts
 * that column to a Carbon instance, which does not compare meaningfully against
 * a string. Formatting here keeps the comparison a string-to-string one.
 */
final class PmEvaluationBatch
{
    /**
     * @param  Collection<string, AssetMeterReading>  $readings
     * @param  Collection<string, Collection<int, array<string, mixed>>>  $suppressions
     */
    private function __construct(
        public readonly Collection $readings,
        public readonly Collection $suppressions,
    ) {}

    public static function empty(): self
    {
        return new self(collect(), collect());
    }

    /**
     * @param  Collection<int, AssetPmAssignment>  $assignments
     */
    public static function for(Collection $assignments): self
    {
        if ($assignments->isEmpty()) {
            return self::empty();
        }

        return new self(
            self::latestConfirmedReadings($assignments),
            self::activeSuppressions($assignments),
        );
    }

    /**
     * The latest confirmed reading per (asset, reading type), in two grouped
     * queries rather than one per assignment.
     *
     * "Latest" means latest by `reading_at`, matching the per-assignment path.
     * The second query re-fetches only rows whose timestamp is some asset's
     * maximum, then ascending order plus `keyBy` leaves each key holding that
     * asset's own newest row — an unrelated asset sharing the timestamp cannot
     * displace it, because every asset's own maximum is in the set.
     *
     * @param  Collection<int, AssetPmAssignment>  $assignments
     * @return Collection<string, AssetMeterReading>
     */
    private static function latestConfirmedReadings(Collection $assignments): Collection
    {
        $assetIds = $assignments->pluck('asset_id')->unique()->values();
        $readingTypeIds = $assignments
            ->map(fn (AssetPmAssignment $a) => $a->pmRule?->usage_reading_type_id)
            ->filter()
            ->unique()
            ->values();

        if ($readingTypeIds->isEmpty()) {
            return collect();
        }

        $confirmed = fn () => AssetMeterReading::query()
            ->whereNotNull('confirmed_at')
            ->whereIn('asset_id', $assetIds)
            ->whereIn('usage_reading_type_id', $readingTypeIds);

        $latestAt = $confirmed()
            ->selectRaw('max(reading_at) as latest_at')
            ->groupBy('asset_id', 'usage_reading_type_id')
            ->pluck('latest_at')
            ->unique()
            ->values();

        if ($latestAt->isEmpty()) {
            return collect();
        }

        return $confirmed()
            ->whereIn('reading_at', $latestAt->all())
            ->orderBy('reading_at')
            ->get()
            ->keyBy(fn (AssetMeterReading $r) => "{$r->asset_id}_{$r->usage_reading_type_id}");
    }

    /**
     * Suppressions that could still be in force, keyed by assignment and axis.
     *
     * Suppressions are stored against the (pm_rule_id, asset_id) pair, which is
     * exactly what an assignment is, so they are re-keyed onto assignment ids
     * here. Date suppressions are filtered to the still-current ones in SQL;
     * reading suppressions cannot be, since the cut-off is compared against a
     * meter value the calculator resolves per assignment.
     *
     * @param  Collection<int, AssetPmAssignment>  $assignments
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private static function activeSuppressions(Collection $assignments): Collection
    {
        $assignmentIds = $assignments->mapWithKeys(
            fn (AssetPmAssignment $a) => ["{$a->pm_rule_id}_{$a->asset_id}" => $a->id],
        );

        $rows = PmOccurrenceSuppression::query()
            ->whereIn('pm_rule_id', $assignments->pluck('pm_rule_id')->unique()->values())
            ->whereIn('asset_id', $assignments->pluck('asset_id')->unique()->values())
            ->where(function ($query) {
                $query
                    ->where(fn ($q) => $q
                        ->where('triggered_by_date', true)
                        ->where('suppressed_until_date', '>=', now()->toDateString()))
                    ->orWhere('triggered_by_reading', true);
            })
            ->get();

        $map = collect();

        foreach ($rows as $row) {
            $assignmentId = $assignmentIds->get("{$row->pm_rule_id}_{$row->asset_id}");

            if ($assignmentId === null) {
                continue;
            }

            $payload = [
                'suppressed_until_date' => $row->suppressed_until_date?->toDateString(),
                'suppressed_until_reading' => $row->suppressed_until_reading,
            ];

            if ($row->triggered_by_date && $payload['suppressed_until_date'] !== null) {
                $map->put(
                    "{$assignmentId}_date",
                    $map->get("{$assignmentId}_date", collect())->push($payload),
                );
            }

            if ($row->triggered_by_reading && $payload['suppressed_until_reading'] !== null) {
                $map->put(
                    "{$assignmentId}_reading",
                    $map->get("{$assignmentId}_reading", collect())->push($payload),
                );
            }
        }

        return $map;
    }
}
