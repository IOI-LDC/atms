<?php

namespace App\Queries\Reports;

use App\Models\Asset;
use InvalidArgumentException;

/**
 * Shared key/label resolution for the asset-level report dimensions.
 *
 * MTBF, MTTR, and Bad Actors group their activity rows through this resolver
 * so every report presents identical keys and labels for the same dimension.
 * Location and Technician remain inside their applicable queries because they
 * are not asset attributes.
 *
 * Key rules:
 * - Maintenance Category: stable `code` (never the mutable display name);
 * - Asset Class: `fa_subclass_code` verbatim;
 * - Size: canonical `numeric(9,5)` string so equivalent notations collapse;
 * - Missing values land in explicit, stable null buckets.
 */
final class AssetReportDimension
{
    /**
     * Resolve the group key, label, and optional Asset for a dimension.
     *
     * The `asset` entry carries the loaded model so callers can serialize it
     * through AssetIdentityResource without re-querying.
     *
     * @return array{key: int|string, label: string|null, asset?: Asset}
     *
     * @throws InvalidArgumentException
     */
    public function resolve(Asset $asset, string $dimension): array
    {
        return match ($dimension) {
            'asset' => [
                'key' => $asset->id,
                'label' => $asset->name,
                'asset' => $asset,
            ],
            'maintenance_category' => [
                'key' => $asset->maintenanceCategory?->code ?? 'uncategorised',
                'label' => $asset->maintenanceCategory?->name ?? 'Uncategorised',
            ],
            'asset_class' => [
                'key' => $asset->fa_subclass_code ?: 'unclassified',
                'label' => $asset->fa_subclass_code ?: 'Unclassified',
            ],
            'size' => [
                'key' => $asset->size_inches?->canonical() ?? 'unspecified',
                'label' => $asset->size_inches?->format() ?? 'Unspecified',
            ],
            default => throw new InvalidArgumentException(
                "Unsupported asset report dimension [{$dimension}]."
            ),
        };
    }
}
