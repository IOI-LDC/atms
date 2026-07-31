<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Attachment extends Model
{
    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'file_hash',
        'description',
        'uploaded_by_user_id',
        'deleted_at',
        'deleted_by_user_id',
    ];

    protected $hidden = [
        'stored_path',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('not-deleted', function ($builder) {
            $builder->whereNull('attachments.deleted_at');
        });
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public static function withTrashed()
    {
        return (new static)->newQueryWithoutScope('not-deleted');
    }

    public function streamDownload(): StreamedResponse
    {
        $disk = config('atms.attachment_disk', 'attachments');

        return Storage::disk($disk)->download(
            $this->stored_path,
            $this->original_name,
            ['Content-Type' => $this->mime_type]
        );
    }

    public static function getMorphMap(): array
    {
        return [
            'asset' => Asset::class,
            'part' => Part::class,
            'maintenance_request' => MaintenanceRequest::class,
            'work_order' => WorkOrder::class,
        ];
    }
}
