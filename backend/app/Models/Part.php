<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Part extends Model
{
    protected $fillable = [
        'erp_part_id',
        'erp_part_code',
        'name',
        'description',
        'unit_of_measure',
        'category',
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

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
