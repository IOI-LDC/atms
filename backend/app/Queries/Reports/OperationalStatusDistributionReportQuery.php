<?php

namespace App\Queries\Reports;

use App\Enums\OperationalStatus;
use App\Models\Asset;

/**
 * R-10A: count of assets per operational_status (always all four values,
 * filling 0 for any with no assets). `include_inactive` controls only
 * soft-deactivated (is_active=false) assets — the default excludes them.
 */
class OperationalStatusDistributionReportQuery
{
    /**
     * @param  array{asset_kind?: ?string, include_inactive?: bool}  $filters
     * @return array{summary: array{total: int}, items: array<int, array{status: string, count: int}>}
     */
    public function handle(array $filters): array
    {
        $query = Asset::query();

        if ($filters['asset_kind'] ?? null) {
            $query->where('asset_kind', $filters['asset_kind']);
        }

        if (! ($filters['include_inactive'] ?? false)) {
            $query->where('is_active', true);
        }

        $rows = (clone $query)
            ->selectRaw('operational_status, count(*) as count')
            ->groupBy('operational_status')
            ->pluck('count', 'operational_status');

        $items = [];
        foreach (OperationalStatus::cases() as $status) {
            $items[] = ['status' => $status->value, 'count' => (int) ($rows[$status->value] ?? 0)];
        }

        return [
            'summary' => ['total' => (int) $query->count()],
            'items' => $items,
        ];
    }
}
