<?php

namespace App\Queries\Dashboard;

use App\Enums\MaintenanceStatus;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;

/**
 * Programme readiness — how much of the asset register is actually set up to be
 * maintained and measured.
 *
 * These are current-state data-completeness figures, not performance KPIs. They
 * exist because every reliability metric on the dashboard is meaningless until
 * they approach 100%: an asset with no PM assignment generates no preventive
 * work, an asset with no location cannot be counted as deployed, and an asset
 * with no meter reading has no baseline for reading-triggered PM.
 *
 * The band is intended to be retired once coverage is high — it is a rollout
 * instrument, not a permanent fixture.
 *
 * Population matches {@see AssetUtilisationQuery}: active and enrolled assets.
 */
class ProgramReadinessQuery
{
    /**
     * @return array{
     *     readiness: array{
     *         total: int,
     *         pm_coverage: array{covered: int, percentage: float|null},
     *         location_recorded: array{covered: int, percentage: float|null},
     *         baseline_reading: array{covered: int, percentage: float|null},
     *     },
     * }
     */
    public function handle(): array
    {
        $total = $this->base()->count();

        $withPm = $this->base()
            ->whereHas('pmAssignments', fn ($q) => $q->where('is_active', true))
            ->count();

        $withLocation = $this->base()->whereNotNull('current_location_id')->count();

        $withReading = $this->base()->whereHas('meterReadings')->count();

        return [
            'readiness' => [
                'total' => $total,
                'pm_coverage' => $this->ratio($withPm, $total),
                'location_recorded' => $this->ratio($withLocation, $total),
                'baseline_reading' => $this->ratio($withReading, $total),
            ],
        ];
    }

    /**
     * @return Builder<Asset>
     */
    private function base()
    {
        return Asset::query()
            ->where('is_active', true)
            ->where('maintenance_status', MaintenanceStatus::ENROLLED->value);
    }

    /**
     * @return array{covered: int, percentage: float|null}
     */
    private function ratio(int $covered, int $total): array
    {
        return [
            'covered' => $covered,
            'percentage' => $total > 0 ? round($covered / $total * 100, 1) : null,
        ];
    }
}
