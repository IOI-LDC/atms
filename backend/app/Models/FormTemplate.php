<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormTemplate extends Model
{
    protected $fillable = [
        'name',
        'fa_subclass_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(FormTemplateField::class)->orderBy('sort_order');
    }

    /**
     * Resolve the single active template for a given FA subclass code, if any.
     * Returns null when no active template exists (the WO then has no form).
     */
    public static function activeForSubclass(string $faSubclassCode): ?self
    {
        return static::where('fa_subclass_code', $faSubclassCode)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Scope a query to active templates only.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
