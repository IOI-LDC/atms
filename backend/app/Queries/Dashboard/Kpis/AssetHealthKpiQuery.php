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
 * Availability is the share of operationally-ready assets out of all
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
     *         by_status: array{ready_for_field: int, under_maintenance: int, down: int, scraped: int, under_inspection: int, lih: int},
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
        $readyForField = $byStatus[OperationalStatus::READY_FOR_FIELD->value] ?? 0;

        return [
            'asset_health' => [
                'availability' => [
                    'percentage' => $total > 0 ? round($readyForField / $total * 100, 1) : null,
                ],
                'by_status' => [
                    'ready_for_field' => $readyForField,
                    'under_maintenance' => $byStatus[OperationalStatus::UNDER_MAINTENANCE->value] ?? 0,
                    'down' => $byStatus[OperationalStatus::DOWN->value] ?? 0,
                    'scraped' => $byStatus[OperationalStatus::SCRAPED->value] ?? 0,
                    'under_inspection' => $byStatus[OperationalStatus::UNDER_INSPECTION->value] ?? 0,
                    'lih' => $byStatus[OperationalStatus::LIH->value] ?? 0,
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
