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
 * - Size: canonical `numeric(9,5)` string so equivalent notations collapse;
 * - Missing values land in explicit, stable null buckets.
 *
 * **FA Subclass is deliberately absent.** It is written by the ERP sync, so
 * ATMS cannot govern it; reports group and filter on Maintenance Category,
 * which is ATMS-owned. Do not reinstate an `asset_class` dimension.
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
