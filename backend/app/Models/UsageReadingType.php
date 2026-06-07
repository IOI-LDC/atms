<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageReadingType extends Model
{
    protected $fillable = [
        'name',
        'unit',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
