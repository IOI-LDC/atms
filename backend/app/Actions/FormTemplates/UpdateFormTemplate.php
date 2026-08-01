<?php

namespace App\Actions\FormTemplates;

use App\Models\FormTemplate;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateFormTemplate
{
    public function execute(FormTemplate $template, array $data, int $userId): FormTemplate
    {
        return DB::transaction(function () use ($template, $data, $userId) {
            $locked = FormTemplate::where('id', $template->id)->lockForUpdate()->first();

            $before = $locked->load('maintenanceCategories')->toArray();

            $locked->update([
                'name' => $data['name'] ?? $locked->name,
            ]);

            // Coverage is editable, unlike the fa_subclass_code it replaced.
            // Which categories a form serves is an ATMS decision that legitimately
            // changes as the register grows, so it is corrected here rather than
            // by rebuilding the template and losing its fields.
            if (array_key_exists('maintenance_category_ids', $data)) {
                if ($locked->is_active) {
                    FormTemplateCategoryPivot::guardNoActiveConflict($data['maintenance_category_ids'], $locked->id);
                }

                $locked->maintenanceCategories()->sync(
                    FormTemplateCategoryPivot::payload($data['maintenance_category_ids'], $locked->is_active),
                );
            }

            $after = $locked->fresh()->load('maintenanceCategories')->toArray();
            app(AuditLogger::class)->log('form_template.updated', $locked, $before, $after, ['user_id' => $userId]);

            return $locked->fresh()->load(['fields', 'maintenanceCategories']);
        });
    }
}
