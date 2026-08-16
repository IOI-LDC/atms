<?php

namespace App\Models;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\PmAssignmentOrigin;
use App\Enums\WorkOrderStatus;
use App\Support\Assets\AssetWorkEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetPmAssignment extends Model
{
    protected $fillable = [
        'asset_id',
        'pm_rule_id',
        'origin',
        'source_maintenance_category_id',
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
        'origin' => PmAssignmentOrigin::class,
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

    /** The category link that created this row, for CATEGORY-origin rows only. */
    public function sourceMaintenanceCategory(): BelongsTo
    {
        return $this->belongsTo(MaintenanceCategory::class, 'source_maintenance_category_id');
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
     * The population a PM evaluation considers: an active assignment, on an
     * active rule, for an asset that may still receive new work.
     *
     * Asset eligibility is delegated to {@see AssetWorkEligibility::scope()} so
     * this set cannot drift from the guard the request paths use. It used to
     * check `maintenance_status` alone, which left the scheduled job raising PM
     * requests for deactivated assets that every hand-written entry point
     * refused — and `is_active = false` is now the only "out of ATMS" control.
     *
     * `UpcomingPmReportQuery` mirrors this set — keep the two in step.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEvaluable(Builder $query): void
    {
        $query
            ->where('asset_pm_assignments.is_active', true)
            ->whereHas('pmRule', fn ($q) => $q->where('is_active', true))
            ->whereHas('asset', fn ($q) => AssetWorkEligibility::scope($q));
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

    /**
     * Meter usage accumulated since this schedule was last serviced.
     *
     * Null when the schedule is not reading-based, when the asset has no confirmed
     * reading, or when no baseline has been set yet — all three mean "not known"
     * rather than zero, and a zero would read as "serviced just now".
     */
    public function usageSinceLastService(): ?float
    {
        if ($this->last_triggered_reading === null) {
            return null;
        }

        $latest = $this->latestConfirmedReading();

        if (! $latest) {
            return null;
        }

        return round((float) $latest->reading_value - (float) $this->last_triggered_reading, 2);
    }

    /**
     * Memoised per instance: AssetPmAssignmentResource asks for this twice while
     * serialising one row — once for reading progress, once for usage since the
     * last service — and the resource is rendered for every assignment in a list.
     * Without the cache that is two queries per row instead of one.
     *
     * Callers that need it across many assignments at once should still preload
     * through PmEvaluationBatch rather than relying on this.
     */
    private ?AssetMeterReading $latestConfirmedReadingCache = null;

    private bool $latestConfirmedReadingLoaded = false;

    public function latestConfirmedReading(): ?AssetMeterReading
    {
        if ($this->latestConfirmedReadingLoaded) {
            return $this->latestConfirmedReadingCache;
        }

        $readingTypeId = $this->pmRule?->usage_reading_type_id;

        $this->latestConfirmedReadingLoaded = true;
        $this->latestConfirmedReadingCache = $readingTypeId
            ? AssetMeterReading::where('asset_id', $this->asset_id)
                ->where('usage_reading_type_id', $readingTypeId)
                ->whereNotNull('confirmed_at')
                ->orderByDesc('reading_at')
                ->first()
            : null;

        return $this->latestConfirmedReadingCache;
    }
}
