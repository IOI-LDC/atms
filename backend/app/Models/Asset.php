<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    protected $casts = [
        'erp_raw_data' => 'array',
        'erp_last_synced_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function currentLocation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    public function locationHistories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetLocationHistory::class);
    }

    public function meterReadings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetMeterReading::class);
    }
}
