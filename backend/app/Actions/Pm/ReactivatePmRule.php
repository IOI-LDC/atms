<?php

namespace App\Actions\Pm;

use App\Models\PmRule;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReactivatePmRule
{
    public function execute(PmRule $rule, int $reactivatedByUserId): PmRule
    {
        return DB::transaction(function () use ($rule, $reactivatedByUserId) {
            $logger = app(AuditLogger::class);
            $locked = PmRule::where('id', $rule->id)->lockForUpdate()->first();

            if ($locked->is_active) {
                throw new DomainException('PM rule is already active.');
            }

            $before = $locked->toArray();

            $locked->update([
                'is_active' => true,
                'reactivated_by' => $reactivatedByUserId,
                'reactivated_at' => now(),
            ]);

            $after = $locked->fresh()->toArray();
            $logger->log('reactivate_pm_rule', $locked, $before, $after);

            return $locked->fresh();
        });
    }
}