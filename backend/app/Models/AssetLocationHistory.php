<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetLocationHistory extends Model
{
    const UPDATED_AT = null; // As per migration, only created_at

    protected $fillable = [
        'asset_id',
        'from_location_id',
        'to_location_id',
        'effective_at',
        'reason',
        'notes',
        'changed_by_user_id',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
