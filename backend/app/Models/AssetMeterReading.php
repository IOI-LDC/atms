<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetMeterReading extends Model
{
    const UPDATED_AT = null; // As per migration

    protected $fillable = [
        'asset_id',
        'usage_reading_type_id',
        'reading_value',
        'reading_at',
        'source',
        'entered_by_user_id',
        'maintenance_request_id',
        'confirmed_by_user_id',
        'confirmed_at',
        'notes',
    ];

    protected $casts = [
        'reading_value' => 'decimal:2',
        'reading_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function readingType(): BelongsTo
    {
        return $this->belongsTo(UsageReadingType::class, 'usage_reading_type_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
