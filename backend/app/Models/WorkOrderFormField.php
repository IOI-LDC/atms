<?php

namespace App\Models;

use App\Enums\FormFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderFormField extends Model
{
    protected $fillable = [
        'work_order_form_id',
        'form_template_field_id',
        'uuid',
        'label',
        'field_type',
        'has_pre_post',
        'unit',
        'is_required',
        'sort_order',
        'pre_value',
        'post_value',
        'notes',
    ];

    protected $casts = [
        'field_type' => FormFieldType::class,
        'has_pre_post' => 'boolean',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function workOrderForm(): BelongsTo
    {
        return $this->belongsTo(WorkOrderForm::class);
    }

    public function formTemplateField(): BelongsTo
    {
        return $this->belongsTo(FormTemplateField::class);
    }
}
