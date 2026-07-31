<?php

namespace App\Models;

use App\Enums\FormFieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormTemplateField extends Model
{
    protected $fillable = [
        'form_template_id',
        'uuid',
        'label',
        'field_type',
        'has_pre_post',
        'unit',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'field_type' => FormFieldType::class,
        'has_pre_post' => 'boolean',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function formTemplate(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class);
    }
}
