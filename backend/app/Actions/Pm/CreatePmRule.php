<?php

namespace App\Actions\Pm;

use App\Models\PmRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreatePmRule
{
    public function execute(array $data, int $createdByUserId): PmRule
    {
        return DB::transaction(function () use ($data, $createdByUserId) {
            $rule = PmRule::create([
                'name' => $data['name'],
                'maintenance_level' => $data['maintenance_level'] ?? null,
                'description' => $data['description'] ?? null,
                'trigger_type' => $data['trigger_type'],
                'interval_days' => $data['interval_days'] ?? null,
                'interval_reading' => $data['interval_reading'] ?? null,
                'usage_reading_type_id' => $data['usage_reading_type_id'] ?? null,
                'is_active' => true,
                'created_by' => $createdByUserId,
            ]);

            $rule->load(['usageReadingType', 'createdBy']);
            $rule->loadCount(['assignments' => fn ($q) => $q->where('is_active', true)]);

            app(AuditLogger::class)->log('pm_rule.created', $rule, [], $rule->toArray());

            return $rule;
        });
    }
}
