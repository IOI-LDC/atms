<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderForm extends Model
{
    protected $fillable = [
        'work_order_id',
        'form_template_id',
        'snapshotted_at',
        'sync_dismissed_at',
    ];

    protected $casts = [
        'snapshotted_at' => 'datetime',
        'sync_dismissed_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(WorkOrderFormField::class)->orderBy('sort_order');
    }

    /**
     * Computed: whether the source template has been edited since this form was
     * snapshotted. False when the template was deleted (soft FK is null).
     */
    public function templateIsStale(): bool
    {
        if (! $this->template || ! $this->snapshotted_at) {
            return false;
        }

        return $this->snapshotted_at->lt($this->template->updated_at);
    }
}
