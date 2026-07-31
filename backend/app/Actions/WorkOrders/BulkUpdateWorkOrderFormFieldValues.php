<?php

namespace App\Actions\WorkOrders;

use App\Models\WorkOrder;
use App\Models\WorkOrderForm;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class BulkUpdateWorkOrderFormFieldValues
{
    /**
     * Persist a set of form field values in one atomic operation.
     *
     * Payloads are partial in both directions: only the fields the client
     * changed need to be present, and within a field an absent slot key keeps
     * the stored value — the same semantics as the single-field PATCH. Every id
     * must still belong to this work order's form; a stale id (the field was
     * dropped by a template sync between open and save) rejects the whole batch
     * rather than writing part of it.
     *
     * @param  array<int, array{id: int, pre_value?: ?string, post_value?: ?string, notes?: ?string}>  $fields
     */
    public function execute(WorkOrder $workOrder, array $fields, int $userId): WorkOrderForm
    {
        return DB::transaction(function () use ($workOrder, $fields, $userId) {
            // The same parent-row lock every other form mutation acquires, so a
            // concurrent sync cannot interleave with this batch. Field rows are
            // locked too, matching UpdateWorkOrderFormFieldValue.
            $form = $workOrder->workOrderForm()->lockForUpdate()->first();

            if (! $form) {
                throw new DomainException('This work order has no attached form.');
            }

            $existing = $form->fields()->lockForUpdate()->get()->keyBy('id');

            $unknown = collect($fields)->pluck('id')->reject(fn (int $id) => $existing->has($id));

            if ($unknown->isNotEmpty()) {
                throw new DomainException('These form fields do not belong to this work order: '.$unknown->implode(', ').'. The form may have changed — reload it and try again.');
            }

            $before = [];
            $after = [];

            foreach ($fields as $payload) {
                $field = $existing->get($payload['id']);

                // Key order matches $original below so the strict comparison
                // holds; a loose one would wrongly equate null with ''.
                $values = [
                    'pre_value' => array_key_exists('pre_value', $payload) ? $payload['pre_value'] : $field->pre_value,
                    'post_value' => array_key_exists('post_value', $payload) ? $payload['post_value'] : $field->post_value,
                    'notes' => array_key_exists('notes', $payload) ? $payload['notes'] : $field->notes,
                ];

                $original = $field->only(['pre_value', 'post_value', 'notes']);

                if ($values === $original) {
                    continue;
                }

                $field->update($values);

                $before[$field->id] = $original;
                $after[$field->id] = $values;
            }

            // One audit entry per save, keyed by field id. A save that changed
            // nothing writes no entry at all.
            if (! empty($after)) {
                app(AuditLogger::class)->log('work_order_form.field_values_updated', $form, $before, $after, [
                    'user_id' => $userId,
                    'work_order_id' => $workOrder->id,
                    'field_count' => count($after),
                ]);
            }

            return $form->fresh()->load(['fields', 'template.fields']);
        });
    }
}
