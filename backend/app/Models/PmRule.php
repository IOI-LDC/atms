<?php

namespace App\Models;

use App\Enums\PmTriggerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PmRule extends Model
{
    protected $fillable = [
        'asset_id',
        'name',
        'maintenance_level',
        'description',
        'trigger_type',
        'interval_days',
        'interval_reading',
        'usage_reading_type_id',
        'last_triggered_date',
        'last_triggered_reading',
        'is_active',
        'created_by',
        'deactivated_by',
        'deactivated_at',
        'reactivated_by',
        'reactivated_at',
    ];

    protected $casts = [
        'trigger_type' => PmTriggerType::class,
        'interval_days' => 'integer',
        'interval_reading' => 'decimal:2',
        'last_triggered_date' => 'date',
        'last_triggered_reading' => 'decimal:2',
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
        'reactivated_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function usageReadingType(): BelongsTo
    {
        return $this->belongsTo(UsageReadingType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(PmOccurrenceSuppression::class);
    }

    public function hasActiveChain(): bool
    {
        $pendingMr = MaintenanceRequest::where('asset_id', $this->asset_id)
            ->where('pm_rule_id', $this->id)
            ->where('is_preventive', true)
            ->where('status', 'pending_review')
            ->exists();

        if ($pendingMr) {
            return true;
        }

        $activeWo = WorkOrder::where('asset_id', $this->asset_id)
            ->whereHas('maintenanceRequest', fn ($q) => $q->where('pm_rule_id', $this->id)->where('is_preventive', true))
            ->whereIn('status', ['open', 'in_progress', 'completed'])
            ->exists();

        return $activeWo;
    }

    public function latestConfirmedReading(): ?AssetMeterReading
    {
        if (! $this->usage_reading_type_id) {
            return null;
        }

        return AssetMeterReading::where('asset_id', $this->asset_id)
            ->where('usage_reading_type_id', $this->usage_reading_type_id)
            ->whereNotNull('confirmed_at')
            ->orderByDesc('reading_at')
            ->first();
    }
}
