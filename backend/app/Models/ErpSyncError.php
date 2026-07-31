<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpSyncError extends Model
{
    protected $fillable = [
        'erp_sync_job_id',
        'external_id',
        'error_type',
        'error_message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ErpSyncJob::class, 'erp_sync_job_id');
    }
}
