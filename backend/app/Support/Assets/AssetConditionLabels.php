<?php

namespace App\Support\Assets;

use App\Models\MasterDataItem;

/**
 * `condition_status` value → display label, resolved once per request.
 *
 * Four surfaces render a condition now — the asset resource, R-1's rows, R-1's
 * CSV, and the Asset Distribution report's dimension labels — and the lookup was
 * originally private to `AssetResource`. Copying it would have let a report
 * disagree with the record it reports on, which is the one thing a report may
 * never do.
 *
 * **Inactive rows are included on purpose.** A condition an Admin retires stays
 * on every asset that already carried it; dropping it from the map would blank
 * those cells rather than explain them. Only the *pickers* filter to active.
 */
class AssetConditionLabels
{
    private const CACHE_KEY = 'atms.asset_condition_labels';

    /**
     * @return array<string, string>
     */
    public static function map(): array
    {
        // Memoised on the container, which is rebuilt per request and per test,
        // so a renamed label never leaks into the next one. Resolving this per
        // row would cost one query per asset on a list of several hundred.
        if (! app()->bound(self::CACHE_KEY)) {
            app()->instance(self::CACHE_KEY, MasterDataItem::query()
                ->where('group_key', MasterDataItem::ASSET_CONDITIONS)
                ->pluck('label', 'value')
                ->all());
        }

        return app(self::CACHE_KEY);
    }

    /**
     * Unknown values fall back to the raw string rather than null: a condition
     * someone has since deleted still tells the reader more than a blank cell.
     */
    public static function for(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::map()[$value] ?? $value;
    }
}
