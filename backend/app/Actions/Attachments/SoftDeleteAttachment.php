<?php

namespace App\Actions\Attachments;

use App\Enums\WorkOrderStatus;
use App\Models\Attachment;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class SoftDeleteAttachment
{
    /**
     * ⚠️ Re-checks the parent work order **under lock**, and does not trust the
     * policy alone.
     *
     * `AttachmentPolicy::delete` refuses on a closed or cancelled work order,
     * but a policy runs on a row read at the start of the request. Closing a
     * work order requires at least one attachment, so the losing sequence is
     * real and small: this delete passes the policy while the order is still
     * in progress, a close commits, and this then removes the very attachment
     * that permitted the close. The result is a closed work order with no
     * evidence — a state the system otherwise makes unreachable.
     *
     * Work order first, then the attachment: the same order `CancelWorkOrder`
     * and `ClearWorkOrderPmMark` take, so the two cannot deadlock against each
     * other.
     */
    public function execute(Attachment $attachment, int $deletedByUserId): Attachment
    {
        return DB::transaction(function () use ($attachment, $deletedByUserId) {
            $parent = $attachment->attachable;

            if ($parent instanceof WorkOrder) {
                $lockedWorkOrder = WorkOrder::where('id', $parent->id)->lockForUpdate()->first();

                if ($lockedWorkOrder !== null
                    && in_array($lockedWorkOrder->status, [WorkOrderStatus::CLOSED, WorkOrderStatus::CANCELLED], true)) {
                    throw new DomainException(
                        'This work order is '.$lockedWorkOrder->status->value.'. Its attachments are part of the '
                        .'closed record and cannot be removed.'
                    );
                }
            }

            // The model carries a `not-deleted` global scope, so an already
            // deleted row simply does not come back.
            $locked = Attachment::where('id', $attachment->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new DomainException('Attachment is already deleted.');
            }

            $logger = app(AuditLogger::class);
            $before = $locked->toArray();

            $locked->update([
                'deleted_at' => now(),
                'deleted_by_user_id' => $deletedByUserId,
            ]);

            $after = $locked->fresh()->toArray();
            $logger->log('attachment.soft_deleted', $locked, $before, $after);

            return $locked->fresh();
        });
    }
}
