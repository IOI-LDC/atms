<?php

namespace App\Actions\FormTemplates;

use App\Models\FormTemplate;
use App\Models\FormTemplateField;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class DeleteFormField
{
    public function execute(FormTemplateField $field, int $userId): void
    {
        DB::transaction(function () use ($field, $userId) {
            $template = $field->formTemplate;
            $before = $field->toArray();

            $field->delete();

            app(AuditLogger::class)->log('form_template.field_deleted', $template, $before, [], ['user_id' => $userId]);
        });
    }
}
