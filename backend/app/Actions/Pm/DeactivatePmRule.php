<?php

namespace App\Actions\Pm;

use App\Jobs\ReconcilePmCategoryAssignmentsJob;
use App\Models\PmRule;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeactivatePmRule
{
    public function execute(PmRule $rule, int $deactivatedByUserId): PmRule
    {
        return DB::transaction(function () use ($rule, $deactivatedByUserId) {
            $logger = app(AuditLogger::class);
            $locked = PmRule::where('id', $rule->id)->lockForUpdate()->first();

            if (! $locked->is_active) {
                throw new DomainException('PM rule is already inactive.');
            }

            if ($locked->hasAnyActiveChain()) {
                throw new DomainException('Cannot deactivate PM rule while it has an active maintenance chain.');
            }

            $before = $locked->toArray();

            $locked->update([
                'is_active' => false,
                'deactivated_by' => $deactivatedByUserId,
                'deactivated_at' => now(),
            ]);

            $after = $locked->fresh()->toArray();
            $logger->log('deactivate_pm_rule', $locked, $before, $after);

            // Withdraws the assignment rows this rule's category links created.
            DB::afterCommit(fn () => dispatch(ReconcilePmCategoryAssignmentsJob::forRule($locked->id)));

            return $locked->fresh();
        });
    }
}
