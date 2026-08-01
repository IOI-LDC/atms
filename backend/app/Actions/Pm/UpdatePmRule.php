<?php

namespace App\Actions\Pm;

use App\Jobs\ReconcilePmCategoryAssignmentsJob;
use App\Models\PmRule;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdatePmRule
{
    public function execute(PmRule $pmRule, array $data): PmRule
    {
        return DB::transaction(function () use ($pmRule, $data) {
            $categoryIds = $data['maintenance_category_ids'] ?? null;
            unset($data['maintenance_category_ids']);

            $before = $pmRule->load('maintenanceCategories')->toArray();
            $pmRule->update($data);

            if ($categoryIds !== null) {
                $pmRule->maintenanceCategories()->sync(array_unique($categoryIds));
                // Both directions matter: a category added expands onto its
                // assets, one removed withdraws the rows it created.
                DB::afterCommit(fn () => dispatch(ReconcilePmCategoryAssignmentsJob::forRule($pmRule->id)));
            }

            $after = $pmRule->fresh()->load('maintenanceCategories')->toArray();

            app(AuditLogger::class)->log('pm_rule.updated', $pmRule, $before, $after);

            $pmRule->load(['usageReadingType', 'createdBy', 'maintenanceCategories']);
            $pmRule->loadCount(['assignments' => fn ($q) => $q->where('is_active', true)]);

            return $pmRule->fresh();
        });
    }
}
