<?php

namespace App\Queries\Dashboard\Kpis;

use App\Enums\OperationalStatus;
use App\Models\Asset;
use App\Models\Booking;
use App\Queries\Reports\OperationalStatusDistributionReportQuery;

/**
 * Asset health & availability snapshot (current state, not windowed).
 *
 * Reuses R-10A (OperationalStatusDistributionReportQuery), which counts assets
 * per operational_status over non-deactivated (is_active = true) assets.
 * Availability is the share of operationally-ACTIVE assets out of all
 * non-deactivated assets. This is an org-wide executive figure, so no role
 * scoping is applied (consistent with the reports model).
 */
class AssetHealthKpiQuery
{
    public function __construct(private readonly OperationalStatusDistributionReportQuery $distribution) {}

    /**
     * @return array{
     *     asset_health: array{
     *         availability: array{percentage: float|null},
     *         by_status: array{active: int, under_maintenance: int, down: int, inactive: int},
     *         total: int,
     *     },
     * }
     */
    public function handle(): array
    {
        $result = $this->distribution->handle([]);

        $byStatus = [];
        foreach ($result['items'] as $row) {
            $byStatus[$row['status']] = $row['count'];
        }

        $total = $result['summary']['total'];
        $active = $byStatus[OperationalStatus::ACTIVE->value] ?? 0;

        return [
            'asset_health' => [
                'availability' => [
                    'percentage' => $total > 0 ? round($active / $total * 100, 1) : null,
                ],
                'by_status' => [
                    'active' => $active,
                    'under_maintenance' => $byStatus[OperationalStatus::UNDER_MAINTENANCE->value] ?? 0,
                    'down' => $byStatus[OperationalStatus::DOWN->value] ?? 0,
                    'inactive' => $byStatus[OperationalStatus::INACTIVE->value] ?? 0,
                ],
                'by_booking' => $this->bookingCounts(),
                'total' => $total,
            ],
        ];
    }

    /**
     * Booking is a separate axis from operational status: an asset can be booked
     * and under maintenance at the same time. Counted over non-deactivated
     * assets, matching the distribution above.
     *
     * @return array{booked: int, available: int}
     */
    private function bookingCounts(): array
    {
        $today = now()->toDateString();

        $bookedAssetIds = Booking::active()
            ->where('booked_from', '<=', $today)
            ->where('booked_until', '>=', $today)
            ->pluck('asset_id');

        $activeAssets = Asset::where('is_active', true);
        $total = (clone $activeAssets)->count();
        $booked = (clone $activeAssets)->whereIn('id', $bookedAssetIds)->count();

        return ['booked' => $booked, 'available' => $total - $booked];
    }
}
