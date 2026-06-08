<?php

namespace App\Actions\Attachments;

use App\Models\Attachment;
use DomainException;

class SoftDeleteAttachment
{
    public function execute(Attachment $attachment, int $deletedByUserId): Attachment
    {
        if ($attachment->deleted_at !== null) {
            throw new DomainException('Attachment is already deleted.');
        }

        $attachment->update([
            'deleted_at' => now(),
            'deleted_by_user_id' => $deletedByUserId,
        ]);

        return $attachment->fresh();
    }
}
