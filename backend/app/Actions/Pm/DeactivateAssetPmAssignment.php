<?php

namespace App\Actions\Pm;

use App\Models\AssetPmAssignment;
use App\Models\PmOccurrenceSuppression;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeactivateAssetPmAssignment
{
    public function execute(AssetPmAssignment $assignment, int $deactivatedByUserId): AssetPmAssignment
    {
        return DB::transaction(function () use ($assignment, $deactivatedByUserId) {
            $logger = app(AuditLogger::class);
            $locked = AssetPmAssignment::where('id', $assignment->id)->lockForUpdate()->first();

            if (! $locked->is_active) {
                throw new DomainException('PM assignment is already inactive.');
            }

            if ($locked->hasActiveChain()) {
                throw new DomainException('Cannot deactivate PM assignment while it has an active maintenance chain.');
            }

            $before = $locked->toArray();

            $locked->update([
                'is_active' => false,
                'deactivated_by' => $deactivatedByUserId,
                'deactivated_at' => now(),
            ]);

            // Deterministic stale-suppression fix: clear any still-effective
            // suppression windows for this (pm_rule_id, asset_id) pair so a later
            // reactivation is not silently blocked by windows created before
            // deactivation (including future-dated windows). Windows are nulled
            // (not set to today) because the due-check uses ">=" against today, so
            // a same-day value would still suppress. Covers both date and reading
            // windows for the pair.
            PmOccurrenceSuppression::where('pm_rule_id', $locked->pm_rule_id)
                ->where('asset_id', $locked->asset_id)
                ->where(function ($q) {
                    $q->where('suppressed_until_date', '>=', now()->toDateString())
                        ->orWhereNotNull('suppressed_until_reading');
                })
                ->get()
                ->each(function (PmOccurrenceSuppression $suppression) use ($logger) {
                    $beforeSuppression = $suppression->toArray();
                    $suppression->update([
                        'suppressed_until_date' => null,
                        'suppressed_until_reading' => null,
                    ]);
                    $logger->log('deactivate_pm_assignment_clear_suppression', $suppression, $beforeSuppression, $suppression->fresh()->toArray());
                });

            $after = $locked->fresh()->toArray();
            $logger->log('deactivate_pm_assignment', $locked, $before, $after);

            return $locked->fresh();
        });
    }
}
