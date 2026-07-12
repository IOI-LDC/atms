<?php

namespace App\Queries\Reports;

use App\Models\Asset;

/**
 * R-13: Asset Booking / Availability.
 *
 * Shows booked vs freely-available assets by location. An asset is "booked"
 * when is_booked = true, otherwise it's available. Groups by location to
 * show availability per site.
 */
class BookingReportQuery
{
    /**
     * @param  array{location_id?: ?int, asset_kind?: ?string}  $filters
     * @return array{summary: array{total_assets: int, booked_count: int, available_count: int}, items: array<int, array{location_id: ?int, location_name: ?string, total_count: int, booked_count: int, available_count: int}>}
     */
    public function handle(array $filters): array
    {
        $assets = Asset::where('is_active', true)
            ->when($filters['location_id'] ?? null, fn ($q, $v) => $q->where('current_location_id', $v))
            ->when($filters['asset_kind'] ?? null, fn ($q, $v) => $q->where('asset_kind', $v))
            ->with('currentLocation')
            ->get(['id', 'current_location_id', 'is_booked']);

        $totalAssets = $assets->count();
        $bookedCount = $assets->where('is_booked', true)->count();
        $availableCount = $totalAssets - $bookedCount;

        // Group by location
        $grouped = $assets->groupBy('current_location_id');

        $items = $grouped->map(function ($locationAssets, $locationId) {
            $booked = $locationAssets->where('is_booked', true)->count();
            $available = $locationAssets->count() - $booked;
            $first = $locationAssets->first();

            return [
                'location_id' => $locationId,
                'location_name' => $first->currentLocation?->name ?? 'Unassigned',
                'total_count' => $locationAssets->count(),
                'booked_count' => $booked,
                'available_count' => $available,
            ];
        })->values();

        return [
            'summary' => [
                'total_assets' => $totalAssets,
                'booked_count' => $bookedCount,
                'available_count' => $availableCount,
            ],
            'items' => $items->all(),
        ];
    }
}
