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

            $before = $locked->toArray();

            // fa_subclass_code is immutable after creation.
            $locked->update([
                'name' => $data['name'] ?? $locked->name,
            ]);

            $after = $locked->fresh()->toArray();
            app(AuditLogger::class)->log('form_template.updated', $locked, $before, $after, ['user_id' => $userId]);

            return $locked->fresh()->load('fields');
        });
    }
}
