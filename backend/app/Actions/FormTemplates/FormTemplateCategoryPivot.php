<?php

namespace App\Actions\FormTemplates;

use App\Models\FormTemplate;
use App\Models\MaintenanceCategory;
use DomainException;

/**
 * Shared helpers for the `form_template_maintenance_category` pivot.
 *
 * Two things must stay true of that table, and both are easy to get wrong in
 * one action and right in another:
 *
 * 1. The pivot's `is_active` mirrors the template's, because the partial unique
 *    index that guarantees "one active template per category" reads the pivot,
 *    not the template.
 * 2. Nothing may activate a template over a category another active template
 *    already serves — resolution would stop being deterministic. Callers get a
 *    DomainException naming the category and the template holding it, rather
 *    than a 500 from the index.
 */
final class FormTemplateCategoryPivot
{
    /**
     * @param  array<int, int>  $categoryIds
     * @return array<int, array<string, bool>>
     */
    public static function payload(array $categoryIds, bool $isActive): array
    {
        return collect($categoryIds)
            ->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => ['is_active' => $isActive]])
            ->all();
    }

    /**
     * @param  array<int, int>  $categoryIds
     *
     * @throws DomainException
     */
    public static function guardNoActiveConflict(array $categoryIds, int $exceptTemplateId): void
    {
        $conflict = MaintenanceCategory::query()
            ->whereIn('maintenance_categories.id', $categoryIds)
            ->join(
                'form_template_maintenance_category as pivot',
                'pivot.maintenance_category_id',
                '=',
                'maintenance_categories.id',
            )
            ->join('form_templates', 'form_templates.id', '=', 'pivot.form_template_id')
            ->where('pivot.is_active', true)
            ->where('pivot.form_template_id', '!=', $exceptTemplateId)
            ->select('maintenance_categories.name as category_name', 'form_templates.name as template_name')
            ->toBase()
            ->first();

        if ($conflict !== null) {
            throw new DomainException(
                "\"{$conflict->template_name}\" is already the active form for {$conflict->category_name}."
            );
        }
    }

    /**
     * Mirror a template's active flag onto every one of its pivot rows.
     */
    public static function mirrorActiveFlag(FormTemplate $template, bool $isActive): void
    {
        $template->maintenanceCategories()
            ->newPivotStatement()
            ->where('form_template_id', $template->id)
            ->update(['is_active' => $isActive, 'updated_at' => now()]);
    }
}
