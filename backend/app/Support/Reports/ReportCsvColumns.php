<?php

namespace App\Support\Reports;

use App\Models\Asset;
use Closure;

/**
 * Ordered CSV column maps, one per report.
 *
 * Each map is `'Header' => accessor`, where the accessor is either a dot path
 * (for reports whose rows are already arrays) or a closure receiving the row.
 * {@see CsvReportStreamer} handles null, bool, enum, and date formatting, so
 * the maps here only decide *which* value and *what to call it*.
 *
 * Headers are written for the person opening the file in Excel, not for the
 * API: "Asset Tag", not `asset_tag`. Order is deliberate — identity first, then
 * classification, then state, then dates.
 *
 * These are kept beside each other rather than on the Resources because a CSV
 * needs flat columns in a fixed order, while the Resources emit nested objects
 * shaped for the UI. One drifting from the other is intentional and expected.
 */
final class ReportCsvColumns
{
    /**
     * R-1 Assets Status — rows are Asset models straight off the query stream,
     * so the accessors read relations rather than a serialized payload.
     *
     * @return array<string, string|Closure>
     */
    public static function assetStatus(): array
    {
        return [
            'Asset Tag' => 'asset_tag',
            'Name' => 'name',
            'Kind' => 'asset_kind',
            'Maintenance Category' => fn (Asset $a) => $a->maintenanceCategory?->name,
            'Operational Status' => 'operational_status',
            'Booked' => fn (Asset $a) => (bool) $a->is_booked,
            'Location' => fn (Asset $a) => $a->currentLocation?->name,
            'Assigned To' => fn (Asset $a) => $a->workOrders->first()?->assignedTo?->name,
            'Open Work Order' => fn (Asset $a) => $a->workOrders->first()?->number,
            'Created' => 'created_at',
            'Last Update' => 'updated_at',
        ];
    }

    /**
     * R-2 Asset Distribution — one leading column per grouped dimension, in the
     * order requested, so the file drops straight into a pivot table.
     *
     * @param  array<int, string>  $groupBy
     * @return array<string, string|Closure>
     */
    public static function assetDistribution(array $groupBy): array
    {
        $columns = [];

        foreach ($groupBy as $i => $dimension) {
            $columns[self::dimensionHeader($dimension)] = fn (array $row) => $row['groups'][$i]['label'] ?? null;
        }

        return $columns + [
            'Assets' => 'asset_count',
            'Ready for Field' => 'by_operational_status.ready_for_field',
            'Under Maintenance' => 'by_operational_status.under_maintenance',
            'Down' => 'by_operational_status.down',
            'Scraped' => 'by_operational_status.scraped',
            'Under Inspection' => 'by_operational_status.under_inspection',
            'Lost in Hole' => 'by_operational_status.lih',
            'Standalone' => 'by_asset_kind.standalone',
            'Packages' => 'by_asset_kind.package',
            'Components' => 'by_asset_kind.component',
            'Booked' => 'booked_count',
        ];
    }

    /**
     * R-22 Most-Used Assets. The usage column carries its unit in the header —
     * hours, kilometres, and metres are not interchangeable, and a bare number
     * in a spreadsheet loses that context permanently.
     *
     * @return array<string, string|Closure>
     */
    public static function assetUsage(string $groupBy, ?string $unit): array
    {
        $usageHeader = $unit ? "Usage ({$unit})" : 'Usage';

        if ($groupBy !== 'asset') {
            return [
                self::dimensionHeader($groupBy) => 'group_label',
                $usageHeader => 'usage',
                'Assets' => 'asset_count',
                'Readings' => 'reading_count',
            ];
        }

        return [
            'Asset' => 'group_label',
            'Asset Tag' => 'asset.asset_tag',
            $usageHeader => 'usage',
            'Latest Reading' => 'latest_reading',
            'Last Read' => 'last_reading_at',
            'Readings' => 'reading_count',
        ];
    }

    /** Name the grouped column after the dimension that produced it. */
    private static function dimensionHeader(string $groupBy): string
    {
        return match ($groupBy) {
            'maintenance_category' => 'Maintenance Category',
            'size' => 'Size',
            'location' => 'Location',
            'technician' => 'Technician',
            'asset' => 'Asset',
            'rule' => 'PM Rule',
            default => 'Group',
        };
    }
}
