<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PM level the team marked as performed while working a work order.
 *
 * Staged rather than applied: `CloseWorkOrder` converts it into a baseline
 * reset at close, and `CancelWorkOrder` discards it. See the migration for why.
 *
 * At most one per work order — the maintenance ladder is cumulative, so marking
 * L3 already asserts L1 and L2.
 */
class WorkOrderPmMark extends Model
{
    protected $fillable = [
        'work_order_id',
        'asset_pm_assignment_id',
        'marked_by_user_id',
        'marked_at',
    ];

    protected function casts(): array
    {
        return [
            'marked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<AssetPmAssignment, $this>
     */
    public function assetPmAssignment(): BelongsTo
    {
        return $this->belongsTo(AssetPmAssignment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }
}
