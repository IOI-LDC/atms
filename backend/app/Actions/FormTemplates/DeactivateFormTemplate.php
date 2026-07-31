<?php

namespace App\Actions\FormTemplates;

use App\Models\FormTemplate;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeactivateFormTemplate
{
    public function execute(FormTemplate $template, int $userId): FormTemplate
    {
        return DB::transaction(function () use ($template, $userId) {
            $locked = FormTemplate::where('id', $template->id)->lockForUpdate()->first();

            if (! $locked->is_active) {
                throw new DomainException('Form template is already inactive.');
            }

            $before = $locked->toArray();

            $locked->update([
                'is_active' => false,
            ]);

            $after = $locked->fresh()->toArray();
            app(AuditLogger::class)->log('form_template.deactivated', $locked, $before, $after, ['user_id' => $userId]);

            return $locked->fresh()->load('fields');
        });
    }
}
