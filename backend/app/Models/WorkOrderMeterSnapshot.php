<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The asset's meter position, per reading type, at the moment a work order closed.
 *
 * Immutable once written. Editing or deleting the source reading afterwards does
 * not change it — the snapshot records what the meter was understood to read at
 * close, which is what "usage since that job" has to measure against.
 */
class WorkOrderMeterSnapshot extends Model
{
    protected $fillable = [
        'work_order_id',
        'usage_reading_type_id',
        'reading_value',
        'reading_at',
    ];

    protected $casts = [
        'reading_value' => 'decimal:2',
        'reading_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function readingType(): BelongsTo
    {
        return $this->belongsTo(UsageReadingType::class, 'usage_reading_type_id');
    }
}
