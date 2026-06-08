<?php

namespace App\Actions\Pm;

use App\Models\PmRule;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReactivatePmRule
{
    public function execute(PmRule $rule, int $reactivatedByUserId): PmRule
    {
        return DB::transaction(function () use ($rule, $reactivatedByUserId) {
            $locked = PmRule::where('id', $rule->id)->lockForUpdate()->first();

            if ($locked->is_active) {
                throw new DomainException('PM rule is already active.');
            }

            $locked->update([
                'is_active' => true,
                'reactivated_by' => $reactivatedByUserId,
                'reactivated_at' => now(),
            ]);

            return $locked->fresh();
        });
    }
}
