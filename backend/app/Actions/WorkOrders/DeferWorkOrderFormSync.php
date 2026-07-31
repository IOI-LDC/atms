<?php

namespace App\Actions\WorkOrders;

use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeferWorkOrderFormSync
{
    public function execute(WorkOrder $workOrder, int $userId): \App\Models\WorkOrderForm
    {
        return DB::transaction(function () use ($workOrder, $userId) {
            $form = $workOrder->workOrderForm()->lockForUpdate()->first();

            if (! $form) {
                throw new DomainException('This work order has no attached form.');
            }

            $before = $form->toArray();

            $form->update([
                'sync_dismissed_at' => now(),
            ]);

            $after = $form->fresh()->toArray();
            app(AuditLogger::class)->log('work_order_form.sync_deferred', $form, $before, $after, ['user_id' => $userId, 'work_order_id' => $workOrder->id]);

            // Defer only sets sync_dismissed_at; it does not compare template
            // fields, so loading the template alone (for templateIsStale) is
            // sufficient — no need for the heavier template.fields join.
            return $form->fresh()->load(['fields', 'template']);
        });
    }
}
