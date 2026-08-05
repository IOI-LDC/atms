<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\UsageReadingType;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateMeterReading
{
    /**
     * @param  array{reading_value:float, reading_at:\DateTimeInterface, notes:?string}  $attributes
     */
    /**
     * @param  float|null  $enteredDelta  The amount the operator typed, when the edit
     *                                    was made in delta terms. Technicians reading a
     *                                    dial mid-job often know only what has changed
     *                                    since the last reading, not the lifetime total,
     *                                    so they must be able to correct the same number
     *                                    they entered. The caller computes the absolute
     *                                    from it and passes both.
     */
    public function execute(AssetMeterReading $reading, Asset $asset, UsageReadingType $readingType, float $readingValue, \DateTimeInterface $readingAt, ?string $notes, ?float $enteredDelta = null): AssetMeterReading
    {
        return DB::transaction(function () use ($reading, $readingValue, $readingAt, $notes, $enteredDelta) {
            $logger = app(AuditLogger::class);

            $lockedReading = AssetMeterReading::where('id', $reading->id)->lockForUpdate()->first();
            $before = $lockedReading->toArray();

            // Confirmed readings underpin PM-trigger calculations and must remain immutable.
            if ($lockedReading->confirmed_at !== null) {
                throw new DomainException('Confirmed meter readings cannot be edited.');
            }

            // An edit made in delta terms carries the new delta; keep the two in step.
            // An edit made in absolute terms does not, and a delta left over from entry
            // would silently stop matching `reading_value` — so clear it.
            if ($enteredDelta !== null) {
                $lockedReading->entered_delta = $enteredDelta;
            } elseif ((float) $lockedReading->reading_value !== (float) $readingValue) {
                $lockedReading->entered_delta = null;
            }

            $lockedReading->reading_value = $readingValue;
            $lockedReading->reading_at = $readingAt;
            $lockedReading->notes = $notes;
            $lockedReading->save();

            $after = $lockedReading->fresh()->toArray();
            $logger->log('meter_reading.updated', $lockedReading, $before, $after);

            return $lockedReading;
        });
    }
}
