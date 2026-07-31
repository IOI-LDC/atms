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

            // Enforce the 1:1 active-per-subclass invariant explicitly so the
            // caller gets a clean 409 instead of a raw 500 from the partial
            // unique index.
            $conflict = FormTemplate::where('fa_subclass_code', $locked->fa_subclass_code)
                ->where('is_active', true)
                ->where('id', '!=', $locked->id)
                ->exists();

            if ($conflict) {
                throw new DomainException('Another active form template already exists for this subclass.');
            }

            $before = $locked->toArray();

            $locked->update([
                'is_active' => true,
            ]);

            $after = $locked->fresh()->toArray();
            app(AuditLogger::class)->log('form_template.reactivated', $locked, $before, $after, ['user_id' => $userId]);

            return $locked->fresh()->load('fields');
        });
    }
}
