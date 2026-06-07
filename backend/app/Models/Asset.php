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
}
