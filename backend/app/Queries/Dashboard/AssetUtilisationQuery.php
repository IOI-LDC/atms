<?php

namespace App\Queries\Dashboard;

use App\Enums\AssetDeployment;
use App\Enums\MaintenanceStatus;
use App\Enums\OperationalStatus;
use App\Models\Asset;
use App\Models\Booking;

/**
 * Point-in-time asset utilisation — "out for work" versus "idle on base".
 *
 * Population: assets that are active and enrolled in the maintenance programme.
 * Each is bucketed by the type of its current location — see
 * {@see AssetDeployment::forLocationType()}, which owns that mapping.
 *
 * The percentage deliberately uses a narrower denominator than the buckets:
 *
 * - `eligible`  = located and classified, minus anything DOWN or
 *                 UNDER_MAINTENANCE. Equipment that legitimately cannot go out
 *                 should not drag the number down.
 * - `unlocated` = assets with no location at all. Excluded from the denominator
 *                 on purpose: with most of the register unlocated, including
 *                 them would report a utilisation near zero and the figure would
 *                 never be trusted again. It is reported separately so the data
 *                 gap stays visible instead of being hidden inside a ratio.
 *
 * The windowed utilisation *rate* (asset-days deployed over asset-days eligible,
 * reconstructed from asset_location_histories) is a reporting concern and does
 * not belong here — the dashboard carries no date range.
 */
class AssetUtilisationQuery
{
    /**
     * @return array{
     *     utilisation: array{
     *         percentage: float|null,
     *         eligible: int,
     *         deployed_eligible: int,
     *         by_bucket: array{deployed: int, idle: int, maintenance: int},
     *         unlocated: int,
     *         unclassified: int,
     *         booked: int,
     *         total: int,
     *     },
     * }
     */
    public function handle(): array
    {
        $rows = Asset::query()
            ->where('assets.is_active', true)
            ->where('assets.maintenance_status', MaintenanceStatus::ENROLLED->value)
            ->leftJoin('locations', 'locations.id', '=', 'assets.current_location_id')
            ->groupBy('locations.type', 'assets.operational_status')
            ->selectRaw('locations.type as location_type, assets.operational_status as operational_status, count(*) as asset_count')
            ->get();

        $buckets = [
            AssetDeployment::DEPLOYED->value => 0,
            AssetDeployment::IDLE->value => 0,
            AssetDeployment::MAINTENANCE->value => 0,
        ];
        $unlocated = 0;
        $unclassified = 0;
        $eligible = 0;
        $deployedEligible = 0;
        $total = 0;

        foreach ($rows as $row) {
            $count = (int) $row->asset_count;
            $total += $count;

            $locationType = $row->location_type;

            if ($locationType === null) {
                $unlocated += $count;

                continue;
            }

            $bucket = AssetDeployment::forLocationType($locationType);

            if ($bucket === null) {
                $unclassified += $count;

                continue;
            }

            $buckets[$bucket->value] += $count;

            if ($this->countsTowardsEligible($row->operational_status)) {
                $eligible += $count;

                if ($bucket === AssetDeployment::DEPLOYED) {
                    $deployedEligible += $count;
                }
            }
        }

        return [
            'utilisation' => [
                'percentage' => $eligible > 0 ? round($deployedEligible / $eligible * 100, 1) : null,
                'eligible' => $eligible,
                'deployed_eligible' => $deployedEligible,
                'by_bucket' => [
                    'deployed' => $buckets[AssetDeployment::DEPLOYED->value],
                    'idle' => $buckets[AssetDeployment::IDLE->value],
                    'maintenance' => $buckets[AssetDeployment::MAINTENANCE->value],
                ],
                'unlocated' => $unlocated,
                'unclassified' => $unclassified,
                'booked' => $this->bookedCount(),
                'total' => $total,
            ],
        ];
    }

    /**
     * An asset that is DOWN or UNDER_MAINTENANCE cannot be sent out, so it is
     * excluded from the denominator rather than counted as idle capacity.
     */
    private function countsTowardsEligible(mixed $operationalStatus): bool
    {
        $status = $operationalStatus instanceof OperationalStatus
            ? $operationalStatus
            : OperationalStatus::tryFrom((string) $operationalStatus);

        return ! in_array($status, [OperationalStatus::DOWN, OperationalStatus::UNDER_MAINTENANCE], true);
    }

    private function bookedCount(): int
    {
        $today = now()->toDateString();

        $bookedAssetIds = Booking::active()
            ->where('booked_from', '<=', $today)
            ->where('booked_until', '>=', $today)
            ->pluck('asset_id');

        return Asset::query()
            ->where('is_active', true)
            ->where('maintenance_status', MaintenanceStatus::ENROLLED->value)
            ->whereIn('id', $bookedAssetIds)
            ->count();
    }
}
