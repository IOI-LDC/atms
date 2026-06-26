<?php

namespace App\Models;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\PmTriggerType;
use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PmRule extends Model
{
    protected $fillable = [
        'name',
        'maintenance_level',
        'description',
        'trigger_type',
        'interval_days',
        'interval_reading',
        'usage_reading_type_id',
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
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
        'reactivated_at' => 'datetime',
    ];

    public function usageReadingType(): BelongsTo
    {
        return $this->belongsTo(UsageReadingType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_pm_assignments')
            ->using(AssetPmAssignment::class)
            ->withPivot([
                'id',
                'last_triggered_date',
                'last_triggered_reading',
                'is_active',
                'assigned_by',
                'created_at',
                'updated_at',
            ]);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetPmAssignment::class);
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(PmOccurrenceSuppression::class);
    }

    /**
     * A template is protected from deactivation if ANY of its active
     * assignments has an open MR/WO maintenance chain.
     */
    public function hasAnyActiveChain(): bool
    {
        $pendingMr = MaintenanceRequest::where('pm_rule_id', $this->id)
            ->where('is_preventive', true)
            ->where('status', MaintenanceRequestStatus::PENDING_REVIEW)
            ->exists();

        if ($pendingMr) {
            return true;
        }

        return WorkOrder::whereHas('maintenanceRequest', fn ($q) => $q->where('pm_rule_id', $this->id)->where('is_preventive', true))
            ->whereIn('status', [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::COMPLETED])
            ->exists();
    }
}
