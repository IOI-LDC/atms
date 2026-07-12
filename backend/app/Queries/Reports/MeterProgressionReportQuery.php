<?php

namespace App\Queries\Reports;

use App\Models\AssetMeterReading;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * R-20: confirmed meter-reading progression with exact prior-reading deltas.
 */
class MeterProgressionReportQuery
{
    /**
     * @param  array{asset_id?: ?int, usage_reading_type_id?: ?int}  $filters
     * @return array{summary: array{total_readings: int, confirmed_readings: int}, paginator: CursorPaginator}
     */
    public function handle(int $perPage, Carbon $from, Carbon $to, array $filters): array
    {
        $base = AssetMeterReading::query()
            ->whereNotNull('confirmed_at')
            ->whereBetween('reading_at', [$from, $to])
            ->when($filters['asset_id'] ?? null, fn ($query, $assetId) => $query->where('asset_id', $assetId))
            ->when($filters['usage_reading_type_id'] ?? null, fn ($query, $typeId) =>
                $query->where('usage_reading_type_id', $typeId));

        $confirmedCount = (clone $base)->count();

        $previousReading = DB::table('asset_meter_readings as previous')
            ->select('previous.reading_value')
            ->whereColumn('previous.asset_id', 'asset_meter_readings.asset_id')
            ->whereColumn('previous.usage_reading_type_id', 'asset_meter_readings.usage_reading_type_id')
            ->whereNotNull('previous.confirmed_at')
            ->whereNull('previous.deleted_at')
            ->where(function (Builder $query): void {
                $query->whereColumn('previous.reading_at', '<', 'asset_meter_readings.reading_at')
                    ->orWhere(function (Builder $sameTimestamp): void {
                        $sameTimestamp
                            ->whereColumn('previous.reading_at', 'asset_meter_readings.reading_at')
                            ->whereColumn('previous.id', '<', 'asset_meter_readings.id');
                    });
            })
            ->orderByDesc('previous.reading_at')
            ->orderByDesc('previous.id')
            ->limit(1);

        $paginator = (clone $base)
            ->select('asset_meter_readings.*')
            ->addSelect(['previous_reading_value' => $previousReading])
            ->with(['asset', 'readingType'])
            ->orderByDesc('reading_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return [
            'summary' => [
                'total_readings' => $confirmedCount,
                'confirmed_readings' => $confirmedCount,
            ],
            'paginator' => $paginator,
        ];
    }
}
