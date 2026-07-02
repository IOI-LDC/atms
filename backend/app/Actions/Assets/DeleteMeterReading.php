<?php

namespace App\Actions\Assets;

use App\Models\AssetMeterReading;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeleteMeterReading
{
    public function execute(AssetMeterReading $reading): AssetMeterReading
    {
        return DB::transaction(function () use ($reading) {
            $logger = app(AuditLogger::class);

            $lockedReading = AssetMeterReading::where('id', $reading->id)->lockForUpdate()->first();
            $before = $lockedReading->toArray();

            // Confirmed readings underpin PM-trigger calculations and must remain immutable.
            if ($lockedReading->confirmed_at !== null) {
                throw new DomainException('Confirmed meter readings cannot be deleted.');
            }

            // Soft-delete: preserves the row for audit/restore. The actor is
            // recorded by the audit log's user_id field.
            $lockedReading->delete();

            $logger->log('meter_reading.deleted', $lockedReading, $before, []);

            return $lockedReading;
        });
    }
}
