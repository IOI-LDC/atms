<?php

namespace App\Actions\Pm;

use App\Models\PmRule;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeactivatePmRule
{
    public function execute(PmRule $rule, int $deactivatedByUserId): PmRule
    {
        return DB::transaction(function () use ($rule, $deactivatedByUserId) {
            $locked = PmRule::where('id', $rule->id)->lockForUpdate()->first();

            if (! $locked->is_active) {
                throw new DomainException('PM rule is already inactive.');
            }

            if ($locked->hasActiveChain()) {
                throw new DomainException('Cannot deactivate PM rule while it has an active maintenance chain.');
            }

            $locked->update([
                'is_active' => false,
                'deactivated_by' => $deactivatedByUserId,
                'deactivated_at' => now(),
            ]);

            return $locked->fresh();
        });
    }
}
