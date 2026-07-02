<?php

namespace App\Actions\WorkOrders;

use App\Models\FormTemplate;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class SyncWorkOrderFormToLatest
{
    public function execute(WorkOrder $workOrder, int $userId): \App\Models\WorkOrderForm
    {
        return DB::transaction(function () use ($workOrder, $userId) {
            // Lock the parent work_order_forms row. This serializes EVERY form
            // mutation: UpdateWorkOrderFormFieldValue and DeferWorkOrderFormSync
            // also acquire this same parent row lock first, so a sync cannot
            // interleave with a concurrent value update. Field-level locks are
            // therefore unnecessary here, and value-update's existence guard
            // (re-fetch by id within the form, 409 if missing) handles a field
            // dropped by the sync.
            $form = $workOrder->workOrderForm()->lockForUpdate()->first();

            if (! $form) {
                throw new DomainException('This work order has no attached form.');
            }

            $asset = $workOrder->asset;

            if (! $asset || empty($asset->fa_subclass_code)) {
                throw new DomainException('The work order asset has no subclass code.');
            }

            $template = FormTemplate::activeForSubclass($asset->fa_subclass_code)?->load('fields');

            if (! $template) {
                throw new DomainException('No active form template exists for this asset subclass.');
            }

            $before = $form->load('fields')->toArray();

            $templateFieldsByUuid = $template->fields->keyBy('uuid');
            $existingByUuid = $form->fields->keyBy('uuid');

            // Drop WO fields whose uuid is no longer in the template.
            foreach ($existingByUuid as $uuid => $existingField) {
                if (! $templateFieldsByUuid->has($uuid)) {
                    $existingField->delete();
                }
            }

            // Update metadata of matched fields (preserve captured values) and
            // append newly added fields with empty values.
            foreach ($template->fields as $index => $templateField) {
                $payload = [
                    'form_template_field_id' => $templateField->id,
                    'label' => $templateField->label,
                    'field_type' => $templateField->field_type,
                    'has_pre_post' => $templateField->has_pre_post,
                    'unit' => $templateField->unit,
                    'is_required' => $templateField->is_required,
                    'sort_order' => $templateField->sort_order ?? $index,
                ];

                if ($existingByUuid->has($templateField->uuid)) {
                    $existingByUuid->get($templateField->uuid)->update($payload);
                } else {
                    $form->fields()->create(array_merge($payload, [
                        'uuid' => $templateField->uuid,
                        'pre_value' => null,
                        'post_value' => null,
                        'notes' => null,
                    ]));
                }
            }

            $form->update([
                'form_template_id' => $template->id,
                'snapshotted_at' => $template->updated_at,
                'sync_dismissed_at' => null,
            ]);

            $after = $form->fresh()->load('fields')->toArray();
            app(AuditLogger::class)->log('work_order_form.synced', $form, $before, $after, ['user_id' => $userId, 'work_order_id' => $workOrder->id]);

            return $form->fresh()->load(['fields', 'template.fields']);
        });
    }
}
