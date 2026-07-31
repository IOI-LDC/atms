<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessNumberSequence extends Model
{
    protected $fillable = [
        'type',
        'current_value',
    ];

    public static function next(string $type, string $prefix): string
    {
        $sequence = static::lockForUpdate()->where('type', $type)->firstOrFail();
        $sequence->increment('current_value');

        return $prefix.str_pad((string) $sequence->current_value, 6, '0', STR_PAD_LEFT);
    }
}
