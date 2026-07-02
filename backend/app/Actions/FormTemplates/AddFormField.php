<?php

namespace App\Actions\FormTemplates;

use App\Models\FormTemplate;
use App\Models\FormTemplateField;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AddFormField
{
    public function execute(FormTemplate $template, array $data, int $userId): FormTemplateField
    {
        return DB::transaction(function () use ($template, $data, $userId) {
            $field = $template->fields()->create([
                'uuid' => (string) Str::orderedUuid(),
                'label' => $data['label'],
                'field_type' => $data['field_type'],
                'has_pre_post' => $data['has_pre_post'] ?? false,
                'unit' => $data['unit'] ?? null,
                'is_required' => $data['is_required'] ?? false,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            app(AuditLogger::class)->log('form_template.field_added', $template, [], $field->toArray(), ['user_id' => $userId]);

            return $field->fresh();
        });
    }
}
