<?php

namespace App\Queries\Reports;

use Carbon\Carbon;

class AgingBuckets
{
    public const BUCKETS = ['0-7', '8-30', '31-90', '91+'];

    /**
     * Elapsed days from $today back to $past, always non-negative.
     *
     * Carbon 3's diffInDays returns a SIGNED value (past => negative) even
     * without the $absolute flag (verified: -7 for a 7-day-old date), so
     * abs() is required. Matches AssetPmAssignmentResource::dateProgress.
     */
    public static function daysFrom(Carbon $today, Carbon $past): int
    {
        return (int) abs($today->diffInDays($past));
    }

    public static function bucket(int $days): string
    {
        return match (true) {
            $days <= 7 => '0-7',
            $days <= 30 => '8-30',
            $days <= 90 => '31-90',
            default => '91+',
        };
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon} [lower, upper] bounds for the aged column (trigger_date).
     */
    public static function dateBounds(string $bucket, Carbon $today): array
    {
        return match ($bucket) {
            '0-7' => [$today->copy()->subDays(7), $today->copy()->subDay()],
            '8-30' => [$today->copy()->subDays(30), $today->copy()->subDays(8)],
            '31-90' => [$today->copy()->subDays(90), $today->copy()->subDays(31)],
            '91+' => [null, $today->copy()->subDays(91)],
        };
    }
}
