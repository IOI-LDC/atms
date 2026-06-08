<?php

namespace App\Actions\Attachments;

use App\Models\Attachment;
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
            $allowedMimes = config('attachments.allowed_mime_types', []);

            if (! in_array($detectedMime, $allowedMimes)) {
                Storage::disk($disk)->delete($stored);

                throw new DomainException('File content does not match any allowed MIME type.');
            }

            $mimeType = $detectedMime;
        }

        $fileHash = hash_file('sha256', $fullPath);

        return Attachment::create([
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
    }

    private function validateFile(UploadedFile $file): void
    {
        $maxSize = config('attachments.max_size_bytes', 20 * 1024 * 1024);

        if ($file->getSize() > $maxSize) {
            throw new DomainException('File exceeds the maximum allowed size of 20 MB.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = config('attachments.allowed_extensions', []);

        if (! in_array($extension, $allowedExtensions)) {
            throw new DomainException('File extension is not allowed.');
        }

        $allowedMimes = config('attachments.allowed_mime_types', []);
        $clientMime = $file->getMimeType();

        if (! in_array($clientMime, $allowedMimes)) {
            throw new DomainException('File MIME type is not allowed.');
        }
    }
}
