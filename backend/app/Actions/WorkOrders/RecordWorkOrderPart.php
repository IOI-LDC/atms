<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordWorkOrderPart
{
    /**
     * @param  string  $quantity  A decimal string, never a float. The controller
     *                            validates it to at most 2 decimal places and it
     *                            is handed straight to PostgreSQL, so the value
     *                            written and the value subtracted are the same
     *                            digits. Casting through a PHP float here is
     *                            what would reintroduce drift.
     */
    public function execute(
        int $workOrderId,
        int $partId,
        string $quantity,
        int $addedByUserId,
        ?string $notes = null
    ): WorkOrderPart {
        return DB::transaction(function () use ($workOrderId, $partId, $quantity, $addedByUserId, $notes) {
            $logger = app(AuditLogger::class);
            $workOrder = WorkOrder::where('id', $workOrderId)->lockForUpdate()->first();

            if (! in_array($workOrder->status, [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS])) {
                throw new DomainException('Parts can only be added to open or in-progress work orders.');
            }

            // Lock the part row before reading the balance the guard checks, so
            // two concurrent requests for the last unit serialise instead of
            // both reading the same stock and overselling it.
            $part = Part::where('id', $partId)->lockForUpdate()->firstOrFail();
            $stockBefore = $part->available_quantity;

            $this->guardPartIsRequestable($part, $workOrder, $quantity);

            $before = [];
            $partLine = WorkOrderPart::create([
                'work_order_id' => $workOrderId,
                'part_id' => $partId,
                'quantity' => $quantity,
                'notes' => $notes,
                'added_by_user_id' => $addedByUserId,
            ]);

            // Arithmetic in the database against the numeric column. The bound
            // value is a string, so no float ever touches the balance.
            DB::update(
                'UPDATE parts SET available_quantity = available_quantity - ?, updated_at = ? WHERE id = ?',
                [$quantity, now(), $partId],
            );

            $after = $partLine->toArray();
            $logger->log('record_work_order_part', $partLine, $before, $after, [
                'part_id' => $partId,
                'available_quantity_before' => $stockBefore,
                'available_quantity_after' => $part->fresh()->available_quantity,
            ]);

            return $partLine;
        });
    }

    /**
     * Re-apply every rule the filtered part picker already enforces.
     *
     * The picker narrows its list server-side, so these checks exist to reject a
     * direct API call that skips it.
     *
     * Since Q6 (2026-08-16) the balance is decremented on consumption, so these
     * now reject a part this work order's own colleagues have exhausted, not
     * only one ERP reported empty. Call this with the part row already locked.
     */
    private function guardPartIsRequestable(Part $part, WorkOrder $workOrder, string $quantity): void
    {
        if (! $part->is_active) {
            throw new DomainException('That part is inactive and cannot be requested.');
        }

        if ((float) $part->available_quantity <= 0) {
            throw new DomainException('That part is out of stock and cannot be requested.');
        }

        // Distinct from the message above: there is stock, just not enough of
        // it. Naming the available figure saves a round-trip to the parts list.
        //
        // Float is fine *here* and only here. This is a single comparison of two
        // values with at most 3 decimal places — scaled to integers they are far
        // inside the 2^53 a double represents exactly, so no pair that differs
        // can compare equal. The precision rule D2 protects is the stored
        // balance, and that is written by SQL below, never by PHP arithmetic.
        // (bcmath/gmp are not installed in the container, and adding a PHP
        // extension to serve one comparison is not a trade worth making.)
        if ((float) $quantity > (float) $part->available_quantity) {
            throw new DomainException("Insufficient stock: only {$part->available_quantity} available.");
        }

        $asset = $workOrder->asset;

        if ($asset !== null && ! $part->isCompatibleWith($asset)) {
            throw new DomainException('That part is not compatible with this work order\'s asset.');
        }
    }
}
