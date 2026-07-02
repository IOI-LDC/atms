<?php

namespace App\Actions\WorkOrders;

use App\Models\WorkOrder;
use App\Models\WorkOrderFormField;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateWorkOrderFormFieldValue
{
    public function execute(WorkOrder $workOrder, int $fieldId, array $data, int $userId): WorkOrderFormField
    {
        return DB::transaction(function () use ($workOrder, $fieldId, $data, $userId) {
            $form = $workOrder->workOrderForm()->lockForUpdate()->first();

            if (! $form) {
                throw new DomainException('This work order has no attached form.');
            }

            $locked = WorkOrderFormField::where('id', $fieldId)
                ->where('work_order_form_id', $form->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new DomainException('The specified form field does not belong to this work order.');
            }

            $before = $locked->toArray();

            $locked->update([
                'pre_value' => array_key_exists('pre_value', $data) ? $data['pre_value'] : $locked->pre_value,
                'post_value' => array_key_exists('post_value', $data) ? $data['post_value'] : $locked->post_value,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
            ]);

            $after = $locked->fresh()->toArray();
            app(AuditLogger::class)->log('work_order_form.field_value_updated', $locked, $before, $after, ['user_id' => $userId, 'work_order_id' => $workOrder->id]);

            return $locked->fresh();
        });
    }
}
