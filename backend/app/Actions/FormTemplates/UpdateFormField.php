<?php

namespace App\Actions\FormTemplates;

use App\Models\FormTemplateField;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateFormField
{
    public function execute(FormTemplateField $field, array $data, int $userId): FormTemplateField
    {
        return DB::transaction(function () use ($field, $data, $userId) {
            $locked = FormTemplateField::where('id', $field->id)->lockForUpdate()->first();

            $before = $locked->toArray();

            $locked->update([
                'label' => $data['label'] ?? $locked->label,
                'field_type' => $data['field_type'] ?? $locked->field_type,
                'unit' => array_key_exists('unit', $data) ? $data['unit'] : $locked->unit,
                'has_pre_post' => $data['has_pre_post'] ?? $locked->has_pre_post,
                'is_required' => $data['is_required'] ?? $locked->is_required,
            ]);

            $after = $locked->fresh()->toArray();
            app(AuditLogger::class)->log('form_template.field_updated', $locked, $before, $after, ['user_id' => $userId]);

            return $locked->fresh();
        });
    }
}
