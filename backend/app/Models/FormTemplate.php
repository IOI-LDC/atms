<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormTemplate extends Model
{
    protected $fillable = [
        'name',
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
     * The Maintenance Categories this template serves.
     *
     * The pivot mirrors the template's `is_active` so a partial unique index can
     * enforce "at most one active template per category" — the invariant that
     * keeps form resolution deterministic, since an asset has exactly one
     * category. The actions that flip `is_active` are responsible for keeping
     * the mirror true.
     */
    public function maintenanceCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            MaintenanceCategory::class,
            'form_template_maintenance_category',
        )->withPivot('is_active')->withTimestamps();
    }

    /**
     * Resolve the single active template serving a Maintenance Category, if any.
     * Returns null when none exists (the work order then has no form).
     */
    public static function activeForCategory(?int $maintenanceCategoryId): ?self
    {
        if ($maintenanceCategoryId === null) {
            return null;
        }

        return static::query()
            ->where('is_active', true)
            ->whereHas(
                'maintenanceCategories',
                fn (Builder $q) => $q->where('maintenance_categories.id', $maintenanceCategoryId),
            )
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
