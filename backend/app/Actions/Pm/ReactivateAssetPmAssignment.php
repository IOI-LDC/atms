<?php

namespace App\Actions\Pm;

use App\Models\AssetPmAssignment;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReactivateAssetPmAssignment
{
    /** @param  int|null  $reactivatedByUserId  Null when reconciliation restored the row. */
    public function execute(AssetPmAssignment $assignment, ?int $reactivatedByUserId): AssetPmAssignment
    {
        return DB::transaction(function () use ($assignment, $reactivatedByUserId) {
            $logger = app(AuditLogger::class);
            $locked = AssetPmAssignment::where('id', $assignment->id)->lockForUpdate()->first();

            if ($locked->is_active) {
                throw new DomainException('PM assignment is already active.');
            }

            $before = $locked->toArray();

            $locked->update([
                'is_active' => true,
                'reactivated_by' => $reactivatedByUserId,
                'reactivated_at' => now(),
                // The row is live again, so it no longer carries a withdrawal.
                'deactivated_by' => null,
                'deactivated_at' => null,
            ]);

            $after = $locked->fresh()->toArray();
            $logger->log('reactivate_pm_assignment', $locked, $before, $after);

            return $locked->fresh();
        });
    }
}
