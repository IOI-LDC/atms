<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpSyncJob extends Model
{
    protected $fillable = [
        'sync_type',
        'status',
        'started_at',
        'completed_at',
        'total_records',
        'created_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'error_message',
        'triggered_by_user_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function errors(): HasMany
    {
        return $this->hasMany(ErpSyncError::class);
    }
}
