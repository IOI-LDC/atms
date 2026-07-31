<?php

namespace App\Actions\Attachments;

use App\Models\Attachment;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadAttachment
{
    public function execute(
        UploadedFile $file,
        string $attachableType,
        int $attachableId,
        int $uploadedByUserId,
        ?string $description = null,
    ): Attachment {
        $logger = app(AuditLogger::class);
        $before = [];

        $this->validateFile($file);

        $morphAlias = array_search($attachableType, Attachment::getMorphMap()) ?: $attachableType;
        $extension = $file->getClientOriginalExtension();

        $disk = config('atms.attachment_disk', 'attachments');
        $stored = Storage::disk($disk)->putFileAs(
            $morphAlias.'/'.$attachableId,
            $file,
            uniqid().'.'.$extension,
        );

        $fullPath = Storage::disk($disk)->path($stored);
        $detectedMime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $fullPath);

        if ($detectedMime === false || $detectedMime === 'application/x-empty') {
            $mimeType = $file->getMimeType();
        } else {
            if (! $this->mimeIsAllowedForExtension($detectedMime, $extension)) {
                Storage::disk($disk)->delete($stored);

                throw new DomainException(sprintf(
                    '%s does not contain valid %s content (detected %s).',
                    $file->getClientOriginalName(),
                    strtolower($extension),
                    $detectedMime,
                ));
            }

            $mimeType = $detectedMime;
        }

        $fileHash = hash_file('sha256', $fullPath);

        $attachment = Attachment::create([
            'attachable_type' => $morphAlias,
            'attachable_id' => $attachableId,
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $stored,
            'mime_type' => $mimeType,
            'size_bytes' => $file->getSize(),
            'file_hash' => $fileHash,
            'description' => $description,
            'uploaded_by_user_id' => $uploadedByUserId,
        ]);

        $after = $attachment->toArray();
        $logger->log('attachment.uploaded', $attachment, $before, $after);

        return $attachment;
    }

    private function validateFile(UploadedFile $file): void
    {
        $maxSize = config('attachments.max_size_bytes', 20 * 1024 * 1024);

        if ($file->getSize() > $maxSize) {
            throw new DomainException(sprintf(
                '%s exceeds the maximum allowed size of 20 MB.',
                $file->getClientOriginalName(),
            ));
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = config('attachments.allowed_extensions', []);

        if (! in_array($extension, $allowedExtensions)) {
            throw new DomainException(sprintf(
                '%s is not an allowed file type. Accepted: %s.',
                $file->getClientOriginalName(),
                implode(', ', $allowedExtensions),
            ));
        }

        if (! $this->mimeIsAllowedForExtension($file->getMimeType(), $extension)) {
            throw new DomainException(sprintf(
                '%s does not contain valid %s content.',
                $file->getClientOriginalName(),
                $extension,
            ));
        }
    }

    /**
     * Is this sniffed MIME type legitimate for the given extension?
     *
     * Office formats do not sniff to their official type — OOXML files are ZIP
     * containers and legacy .doc/.xls are OLE2 compound documents — so a flat
     * list of official types rejects every Office upload. The extension
     * whitelist remains the security boundary; this narrows the container types
     * to the extensions that may legitimately present them.
     *
     * Falls back to the flat list for any extension without a specific entry.
     */
    private function mimeIsAllowedForExtension(?string $mime, string $extension): bool
    {
        if ($mime === null) {
            return false;
        }

        $map = config('attachments.allowed_mime_types_by_extension', []);
        $allowed = $map[strtolower($extension)] ?? config('attachments.allowed_mime_types', []);

        return in_array($mime, $allowed, true);
    }
}
