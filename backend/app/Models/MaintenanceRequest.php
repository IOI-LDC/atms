<?php

namespace App\Models;

use App\Enums\MaintenanceRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MaintenanceRequest extends Model
{
    protected $fillable = [
        'number',
        'asset_id',
        'type',
        'status',
        'priority',
        'description',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'is_preventive',
        'pm_rule_id',
        'triggered_by_date',
        'triggered_by_reading',
    ];

    protected $casts = [
        'status' => MaintenanceRequestStatus::class,
        'reviewed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'is_preventive' => 'boolean',
        'triggered_by_date' => 'boolean',
        'triggered_by_reading' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function workOrder(): HasOne
    {
        return $this->hasOne(WorkOrder::class);
    }

    public function pmRule(): BelongsTo
    {
        return $this->belongsTo(PmRule::class);
    }
}
