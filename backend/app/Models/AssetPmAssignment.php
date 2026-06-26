<?php

namespace App\Models;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetPmAssignment extends Model
{
    protected $fillable = [
        'asset_id',
        'pm_rule_id',
        'last_triggered_date',
        'last_triggered_reading',
        'is_active',
        'assigned_by',
        'deactivated_by',
        'deactivated_at',
        'reactivated_by',
        'reactivated_at',
    ];

    protected $casts = [
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

    public function pmRule(): BelongsTo
    {
        return $this->belongsTo(PmRule::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function reactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reactivated_by');
    }

    /**
     * Suppressions are scoped to the unique (pm_rule_id, asset_id) pair.
     * Only valid for single-model loading (e.g. the show endpoint); do not
     * eager-load across assignment collections.
     */
    public function suppressions(): HasMany
    {
        return $this->hasMany(PmOccurrenceSuppression::class, 'pm_rule_id', 'pm_rule_id')
            ->where('pm_occurrence_suppressions.asset_id', $this->asset_id);
    }

    /**
     * True if this asset/rule pair has a pending preventive MR or an active WO.
     */
    public function hasActiveChain(): bool
    {
        $pendingMr = MaintenanceRequest::where('asset_id', $this->asset_id)
            ->where('pm_rule_id', $this->pm_rule_id)
            ->where('is_preventive', true)
            ->where('status', MaintenanceRequestStatus::PENDING_REVIEW)
            ->exists();

        if ($pendingMr) {
            return true;
        }

        return WorkOrder::where('asset_id', $this->asset_id)
            ->whereHas('maintenanceRequest', fn ($q) => $q->where('pm_rule_id', $this->pm_rule_id)->where('is_preventive', true))
            ->whereIn('status', [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::COMPLETED])
            ->exists();
    }

    public function latestConfirmedReading(): ?AssetMeterReading
    {
        $readingTypeId = $this->pmRule?->usage_reading_type_id;

        if (! $readingTypeId) {
            return null;
        }

        return AssetMeterReading::where('asset_id', $this->asset_id)
            ->where('usage_reading_type_id', $readingTypeId)
            ->whereNotNull('confirmed_at')
            ->orderByDesc('reading_at')
            ->first();
    }
}
