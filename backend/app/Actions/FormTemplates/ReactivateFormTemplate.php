<?php

namespace App\Actions\FormTemplates;

use App\Models\FormTemplate;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReactivateFormTemplate
{
    public function execute(FormTemplate $template, int $userId): FormTemplate
    {
        return DB::transaction(function () use ($template, $userId) {
            $locked = FormTemplate::where('id', $template->id)->lockForUpdate()->first();

            if ($locked->is_active) {
                throw new DomainException('Form template is already active.');
            }

            $categoryIds = $locked->maintenanceCategories()->pluck('maintenance_categories.id')->all();

            if ($categoryIds === []) {
                throw new DomainException('Assign at least one maintenance category before activating this form.');
            }

            // Enforce the one-active-template-per-category invariant explicitly
            // so the caller gets a clean 409 naming the clash, instead of a raw
            // 500 from the partial unique index behind it.
            FormTemplateCategoryPivot::guardNoActiveConflict($categoryIds, $locked->id);

            $before = $locked->toArray();

            $locked->update([
                'is_active' => true,
            ]);
            FormTemplateCategoryPivot::mirrorActiveFlag($locked, true);

            $after = $locked->fresh()->toArray();
            app(AuditLogger::class)->log('form_template.reactivated', $locked, $before, $after, ['user_id' => $userId]);

            return $locked->fresh()->load(['fields', 'maintenanceCategories']);
        });
    }
}
