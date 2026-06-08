<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class WorkOrder extends Model
{
    protected $fillable = [
        'number',
        'maintenance_request_id',
        'asset_id',
        'status',
        'priority',
        'description',
        'assigned_to_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'started_at',
        'completed_by_user_id',
        'completed_at',
        'completion_notes',
        'closed_by_user_id',
        'closed_at',
        'cancelled_by_user_id',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => WorkOrderStatus::class,
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function maintenanceRequest(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(WorkOrderPart::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
