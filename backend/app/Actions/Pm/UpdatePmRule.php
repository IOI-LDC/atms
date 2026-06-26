<?php

namespace App\Actions\Pm;

use App\Models\PmRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdatePmRule
{
    public function execute(PmRule $pmRule, array $data): PmRule
    {
        return DB::transaction(function () use ($pmRule, $data) {
            $before = $pmRule->toArray();
            $pmRule->update($data);
            $after = $pmRule->fresh()->toArray();

            app(AuditLogger::class)->log('pm_rule.updated', $pmRule, $before, $after);

            $pmRule->load(['usageReadingType', 'createdBy']);
            $pmRule->loadCount(['assignments' => fn ($q) => $q->where('is_active', true)]);

            return $pmRule->fresh();
        });
    }
}
