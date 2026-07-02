<?php

namespace App\Actions\FormTemplates;

use App\Models\FormTemplate;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ReorderFormFields
{
    /**
     * @param  array<int, int>  $fieldIds  Ordered list of FormTemplateField ids.
     */
    public function execute(FormTemplate $template, array $fieldIds, int $userId): FormTemplate
    {
        return DB::transaction(function () use ($template, $fieldIds, $userId) {
            $before = $template->fields()->orderBy('sort_order')->get()->toArray();

            foreach ($fieldIds as $index => $fieldId) {
                $template->fields()
                    ->where('id', $fieldId)
                    ->update(['sort_order' => $index]);
            }

            app(AuditLogger::class)->log('form_template.fields_reordered', $template, $before, ['ordered_field_ids' => $fieldIds], ['user_id' => $userId]);

            return $template->fresh()->load('fields');
        });
    }
}
