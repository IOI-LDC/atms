<?php

namespace App\Queries\Reports;

use App\Enums\MaintenanceStatus;
use App\Enums\OperationalStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Booking;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * R-1: Assets Status Report — the flat asset register LDC asked for.
 *
 * Unlike every other report in the catalogue, this is a *listing*, not an
 * analysis: one row per asset with tag, name, type, status, location, assignee,
 * and timestamps, filterable and exportable.
 *
 * Two interpretations are pinned here because the schema cannot answer the
 * alternatives, and both are surfaced in the UI so nobody misreads the output:
 *
 * 1. **The date range filters `created_at` / `updated_at`, not status history.**
 *    `operational_status` is overwritten in place — there is no asset status
 *    history table — so "status as it stood on 1 June" is unanswerable. This
 *    reports current status for assets created or updated in the range.
 * 2. **"Assigned To" is the technician on the asset's open work order.** Assets
 *    have no custodian or holder column; a work-order assignee is the only
 *    person ATMS associates with an asset. Null when no work order is open.
 *
 * Withdrawn assets are excluded — the ERP owns withdrawal and its sub-statuses.
 */
class AssetStatusReportQuery
{
    /**
     * @param  array{
     *     location_id?: ?int,
     *     operational_status?: ?string,
     *     asset_kind?: ?string,
     *     maintenance_category_id?: ?int,
     *     booked?: ?bool,
     *     from?: ?string,
     *     to?: ?string,
     *     date_field?: ?string,
     * }  $filters
     * @return array{
     *     summary: array{total: int, by_status: array<string, int>, booked: int},
     *     paginator: CursorPaginator,
     *     stream: \Closure(): LazyCollection,
     * }
     */
    public function handle(int $perPage, array $filters): array
    {
        $rows = $this->base($filters)
            ->with(['currentLocation', 'maintenanceCategory'])
            ->with(['workOrders' => fn ($q) => $q
                ->whereIn('status', [WorkOrderStatus::OPEN->value, WorkOrderStatus::IN_PROGRESS->value])
                ->with('assignedTo')
                ->latest('created_at'),
            ])
            ->orderBy('name')
            ->orderBy('id');

        return [
            'summary' => $this->summarise($filters),
            // Cloned because cursorPaginate mutates the builder it runs on.
            'paginator' => (clone $rows)->cursorPaginate($perPage),
            // Every row, unpaginated, for CSV export. Lazy so a register of any
            // size streams at flat memory instead of materialising in one array.
            'stream' => fn () => (clone $rows)->lazy(500),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Asset>
     */
    private function base(array $filters)
    {
        // `updated_at` unless explicitly told otherwise — "Last Update" is the
        // column LDC named, so it is the more useful default of the two.
        $dateField = ($filters['date_field'] ?? null) === 'created_at' ? 'created_at' : 'updated_at';

        return Asset::query()
            ->where('is_active', true)
            ->where('maintenance_status', MaintenanceStatus::ENROLLED->value)
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('current_location_id', $v))
            ->when($filters['operational_status'] ?? null, fn ($q, $v) => $q->where('operational_status', $v))
            ->when($filters['asset_kind'] ?? null, fn ($q, $v) => $q->where('asset_kind', $v))
            ->when($filters['maintenance_category_id'] ?? null, fn ($q, $v) => $q->where('maintenance_category_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate($dateField, '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate($dateField, '<=', $v))
            ->when(isset($filters['booked']), function ($q) use ($filters) {
                $ids = $this->bookedAssetIds();

                return $filters['booked'] ? $q->whereIn('id', $ids) : $q->whereNotIn('id', $ids);
            });
    }

    /**
     * Counts over the same filtered set, so the summary always reconciles with
     * the rows below it.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total: int, by_status: array<string, int>, booked: int}
     */
    private function summarise(array $filters): array
    {
        $byStatus = [];

        foreach (OperationalStatus::cases() as $status) {
            $byStatus[$status->value] = (clone $this->base($filters))
                ->where('operational_status', $status->value)
                ->count();
        }

        return [
            'total' => (clone $this->base($filters))->count(),
            'by_status' => $byStatus,
            'booked' => (clone $this->base($filters))->whereIn('id', $this->bookedAssetIds())->count(),
        ];
    }

    /**
     * Assets with a booking whose window covers today. Booking is derived from
     * the bookings table, never a stored flag.
     *
     * @return array<int, int>
     */
    private function bookedAssetIds(): array
    {
        $today = now()->toDateString();

        return Booking::active()
            ->where('booked_from', '<=', $today)
            ->where('booked_until', '>=', $today)
            ->pluck('asset_id')
            ->all();
    }
}
