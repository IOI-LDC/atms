<?php

namespace App\Models;

use App\Enums\PmTriggerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmOccurrenceSuppression extends Model
{
    protected $fillable = [
        'pm_rule_id',
        'asset_id',
        'maintenance_request_id',
        'trigger_type',
        'decision_type',
        'triggered_by_date',
        'triggered_by_reading',
        'suppressed_until_date',
        'suppressed_until_reading',
        'decided_by',
        'decided_at',
        'reason',
    ];

    protected $casts = [
        'trigger_type' => PmTriggerType::class,
        'triggered_by_date' => 'boolean',
        'triggered_by_reading' => 'boolean',
        'suppressed_until_date' => 'date',
        'suppressed_until_reading' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function pmRule(): BelongsTo
    {
        return $this->belongsTo(PmRule::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function maintenanceRequest(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
