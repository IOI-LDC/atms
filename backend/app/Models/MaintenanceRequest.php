<?php

namespace App\Models;

use App\Enums\MaintenanceRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MaintenanceRequest extends Model
{
    protected $fillable = [
        'number',
        'asset_id',
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
        'is_failure',
        'pm_rule_id',
        'triggered_by_date',
        'triggered_by_reading',
        'trigger_date',
        'trigger_reading_value',
        'trigger_reading_type_id',
    ];

    protected $casts = [
        'status' => MaintenanceRequestStatus::class,
        'reviewed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'is_preventive' => 'boolean',
        'is_failure' => 'boolean',
        'triggered_by_date' => 'boolean',
        'triggered_by_reading' => 'boolean',
        'trigger_date' => 'date',
        'trigger_reading_value' => 'decimal:2',
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

    public function triggerReadingType(): BelongsTo
    {
        return $this->belongsTo(UsageReadingType::class, 'trigger_reading_type_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
