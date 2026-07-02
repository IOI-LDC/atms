<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class WorkOrder extends Model
{
    protected $fillable = [
        'number',
        'maintenance_request_id',
        'asset_id',
        'status',
        'priority',
        'description',
        'assigned_to_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'started_at',
        'completed_by_user_id',
        'completed_at',
        'completion_notes',
        'closed_by_user_id',
        'closed_at',
        'cancelled_by_user_id',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => WorkOrderStatus::class,
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function maintenanceRequest(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(WorkOrderPart::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function workOrderForm(): HasOne
    {
        return $this->hasOne(WorkOrderForm::class);
    }

    /**
     * Whether the WO's attached form has all its required fields filled.
     * Returns true when there is no form instance (the gate does not apply).
     * Booleans are considered answered when non-null (any of '0'/'1').
     *
     * Requires the `workOrderForm.fields` relation to be eager-loaded; if
     * `workOrderForm` is loaded but its `fields` are not, this returns a false
     * positive (empty fields collection). The lone caller (CompleteWorkOrder)
     * loads `workOrderForm.fields`.
     */
    public function isFormComplete(): bool
    {
        if (! $this->workOrderForm) {
            return true;
        }

        return empty($this->missingRequiredFields());
    }

    /**
     * The required form fields (or pre/post slots) that remain unfilled.
     *
     * @return array<int, array{uuid: ?string, label: ?string, missing: array<int, string>}>
     */
    public function missingRequiredFields(): array
    {
        $missing = [];

        if (! $this->workOrderForm) {
            return $missing;
        }

        foreach ($this->workOrderForm->fields as $field) {
            if (! $field->is_required) {
                continue;
            }

            $fieldMissing = [];

            if ($field->has_pre_post) {
                if ($this->isEmptyValue($field->pre_value)) {
                    $fieldMissing[] = 'pre';
                }
                if ($this->isEmptyValue($field->post_value)) {
                    $fieldMissing[] = 'post';
                }
            } else {
                if ($this->isEmptyValue($field->post_value)) {
                    $fieldMissing[] = 'post';
                }
            }

            if (! empty($fieldMissing)) {
                $missing[] = [
                    'uuid' => $field->uuid,
                    'label' => $field->label,
                    'missing' => $fieldMissing,
                ];
            }
        }

        return $missing;
    }

    /**
     * A value counts as "filled" when it is non-null and not an empty string.
     */
    protected function isEmptyValue(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
