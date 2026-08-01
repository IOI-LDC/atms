<?php

namespace App\Actions\FormTemplates;

use App\Models\FormTemplate;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateFormTemplate
{
    public function execute(array $data, int $userId): FormTemplate
    {
        return DB::transaction(function () use ($data, $userId) {
            $template = FormTemplate::create([
                'name' => $data['name'],
                'is_active' => true,
            ]);

            $template->maintenanceCategories()->sync(
                FormTemplateCategoryPivot::payload($data['maintenance_category_ids'], true),
            );

            $template->load(['fields', 'maintenanceCategories']);

            app(AuditLogger::class)->log('form_template.created', $template, [], $template->toArray(), ['user_id' => $userId]);

            return $template;
        });
    }
}
