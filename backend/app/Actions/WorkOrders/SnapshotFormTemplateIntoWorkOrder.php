<?php

namespace App\Actions\WorkOrders;

use App\Models\FormTemplate;
use App\Models\WorkOrder;

/**
 * Snapshots the asset's active FormTemplate (if any) into the Work Order at
 * creation time. The copy is self-contained: field metadata is duplicated into
 * work_order_form_fields so later template edits never affect in-flight WOs.
 * Must run inside the WO-creation transaction. No-ops silently when the asset
 * has no active template for its fa_subclass_code.
 */
class SnapshotFormTemplateIntoWorkOrder
{
    public function execute(WorkOrder $workOrder): void
    {
        $asset = $workOrder->asset;

        if (! $asset || empty($asset->fa_subclass_code)) {
            return;
        }

        $template = FormTemplate::activeForSubclass($asset->fa_subclass_code)?->load('fields');

        if (! $template) {
            return;
        }

        $form = $workOrder->workOrderForm()->create([
            'form_template_id' => $template->id,
            'snapshotted_at' => $template->updated_at,
        ]);

        foreach ($template->fields as $field) {
            $form->fields()->create([
                'form_template_field_id' => $field->id,
                'uuid' => $field->uuid,
                'label' => $field->label,
                'field_type' => $field->field_type,
                'has_pre_post' => $field->has_pre_post,
                'unit' => $field->unit,
                'is_required' => $field->is_required,
                'sort_order' => $field->sort_order,
                'pre_value' => null,
                'post_value' => null,
                'notes' => null,
            ]);
        }
    }
}
