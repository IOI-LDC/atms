<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    protected $fillable = [
        'sharepoint_item_id',
        'emp_id',
        'name',
        'email',
        'department',
        'job_title',
        'source_is_active',
        'source_updated_at',
        'source_raw_data',
        'last_synced_at',
    ];

    protected $casts = [
        'source_is_active' => 'boolean',
        'source_updated_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'source_raw_data' => 'array',
    ];

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
