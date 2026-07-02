<?php

namespace App\Models;

use App\Enums\AssetKind;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceSubStatus;
use App\Enums\OperationalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Asset extends Model
{
    protected $fillable = [
        'erp_asset_code',
        'name',
        'description',
        'category',
        'serial_number',
        'model',
        'manufacturer',
        'current_location_id',
        'operational_status',
        'erp_status',
        'erp_raw_data',
        'erp_last_synced_at',
        'is_active',
        'is_booked',
        'maintenance_status',
        'maintenance_sub_status',
        'asset_kind',
        'asset_tag',
        'asset_tag_generated_at',
        'asset_tag_override_reason',
        'fa_subclass_code',
        'parent_asset_id',
    ];

    protected $hidden = [
        'erp_raw_data',
    ];

    protected function casts(): array
    {
        return [
            'erp_raw_data' => 'array',
            'erp_last_synced_at' => 'datetime',
            'is_active' => 'boolean',
            'is_booked' => 'boolean',
            'asset_tag_generated_at' => 'datetime',
            'operational_status' => OperationalStatus::class,
            'maintenance_status' => MaintenanceStatus::class,
            'maintenance_sub_status' => MaintenanceSubStatus::class,
            'asset_kind' => AssetKind::class,
        ];
    }

    /**
     * Auto-clear booking when an asset is deactivated or removed from the
     * maintenance program. A decommissioned asset cannot remain booked.
     */
    protected static function booted(): void
    {
        static::updating(function (Asset $asset) {
            if ($asset->is_booked && (
                ($asset->isDirty('is_active') && ! $asset->is_active)
                || ($asset->isDirty('maintenance_status') && $asset->maintenance_status === MaintenanceStatus::WITHDRAWN)
            )) {
                $asset->is_booked = false;
            }
        });
    }

    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'parent_asset_id');
    }

    public function childAssets(): HasMany
    {
        return $this->hasMany(Asset::class, 'parent_asset_id');
    }

    public function locationHistories(): HasMany
    {
        return $this->hasMany(AssetLocationHistory::class);
    }

    public function meterReadings(): HasMany
    {
        return $this->hasMany(AssetMeterReading::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function pmAssignments(): HasMany
    {
        return $this->hasMany(AssetPmAssignment::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
