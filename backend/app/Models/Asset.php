<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Asset extends Model
{
    protected $fillable = [
        'erp_asset_id',
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
    ];

    protected $hidden = [
        'erp_raw_data',
    ];

    protected $casts = [
        'erp_raw_data' => 'array',
        'erp_last_synced_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
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

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
