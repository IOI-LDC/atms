<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterDataItem extends Model
{
    protected $fillable = [
        'group_key',
        'value',
        'label',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
