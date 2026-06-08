<?php

namespace App\Actions\Attachments;

use App\Models\Attachment;
use App\Services\Audit\AuditLogger;
use DomainException;

class SoftDeleteAttachment
{
    public function execute(Attachment $attachment, int $deletedByUserId): Attachment
    {
        $logger = app(AuditLogger::class);
        $before = $attachment->toArray();

        if ($attachment->deleted_at !== null) {
            throw new DomainException('Attachment is already deleted.');
        }

        $attachment->update([
            'deleted_at' => now(),
            'deleted_by_user_id' => $deletedByUserId,
        ]);

        $after = $attachment->fresh()->toArray();
        $logger->log('attachment.soft_deleted', $attachment, $before, $after);

        return $attachment->fresh();
    }
}
