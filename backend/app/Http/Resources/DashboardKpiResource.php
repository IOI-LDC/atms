<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes the /dashboard/kpis payload. $wrap is disabled so the response is a
 * flat object (window, kpis, recently_relocated_assets), matching the sibling
 * GET /dashboard endpoint which also returns a flat shape.
 */
class DashboardKpiResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  array{
     *     window: array{days: int, from: \Illuminate\Support\Carbon, to: \Illuminate\Support\Carbon},
     *     kpis: array{
     *         mtbf: array{days: float|null},
     *         failure_rate: array{failures: int, per_day: float},
     *         mttr: array{hours: float|null},
     *         pm_compliance: array{compliant: int, total: int, percentage: float|null},
     *         avg_mr_duration: array{hours: float|null},
     *         avg_wo_duration: array{hours: float|null},
     *         asset_health: array{
     *             availability: array{percentage: float|null},
     *             by_status: array{active: int, under_maintenance: int, down: int, inactive: int},
     *             total: int,
     *         },
     *         workforce: array{
     *             wo_backlog: array{total: int, trend_pct: float|null},
     *             completion_rate: array{closed: int, created: int, percentage: float|null},
     *         },
     *     },
     *     recently_relocated_assets: array<int, mixed>,
     * }  $resource
     */
    public function toArray(Request $request): array
    {
        return [
            'window' => [
                'days' => $this->resource['window']['days'],
                'from' => $this->resource['window']['from']->toIso8601String(),
                'to' => $this->resource['window']['to']->toIso8601String(),
            ],
            'kpis' => [
                'mtbf' => $this->resource['kpis']['mtbf'],
                'failure_rate' => $this->resource['kpis']['failure_rate'],
                'mttr' => $this->resource['kpis']['mttr'],
                'pm_compliance' => $this->resource['kpis']['pm_compliance'],
                'avg_mr_duration' => $this->resource['kpis']['avg_mr_duration'],
                'avg_wo_duration' => $this->resource['kpis']['avg_wo_duration'],
                'asset_health' => $this->resource['kpis']['asset_health'],
                'workforce' => $this->resource['kpis']['workforce'],
            ],
            'recently_relocated_assets' => $this->resource['recently_relocated_assets'],
        ];
    }
}
