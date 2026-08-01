<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WO Form templates are routed by Maintenance Category, not FA subclass.
 *
 * `fa_subclass_code` is written by the ERP sync, so ATMS cannot govern it. A
 * field ATMS does not control must not route behaviour ATMS is accountable for
 * — describing is fine (subclass remains a report dimension), controlling is
 * not. `maintenance_categories` is ATMS-owned and Admin-editable.
 *
 * ## Why a pivot, and where uniqueness lives now
 *
 * One form often serves several categories, so the relationship is many-to-many.
 * An asset still carries exactly **one** category, so form resolution stays
 * deterministic only while at most one *active* template covers any category.
 * That guarantee moves from `form_templates` to the pivot: `is_active` is
 * mirrored onto each row and a partial unique index enforces it, mirroring the
 * `form_templates_active_subclass_unique` backstop this replaces. The controller
 * returns a clean 422 first; the index is the thing that cannot be talked out of it.
 *
 * ## Existing templates migrate deactivated
 *
 * There is no subclass -> category mapping to migrate through (25 categories
 * against 20 subclasses, describing different things). Templates are carried
 * over inactive with no categories attached, for an Admin to reassign
 * deliberately. Deactivating rather than deleting keeps their fields, and every
 * work order form already snapshotted from them is self-contained and untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_template_maintenance_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->foreignId('maintenance_category_id')->constrained('maintenance_categories')->cascadeOnDelete();
            // Mirrored from the parent template so the partial unique index
            // below can see it. Kept in step by the FormTemplate actions.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['form_template_id', 'maintenance_category_id'], 'form_template_category_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX form_template_active_category_unique '
            .'ON form_template_maintenance_category (maintenance_category_id) WHERE is_active = true'
        );

        // No mapping exists, so nothing can be assigned truthfully. Templates
        // survive with their fields; an Admin re-points them by hand.
        DB::table('form_templates')->update(['is_active' => false]);

        DB::statement('DROP INDEX IF EXISTS form_templates_active_subclass_unique');

        Schema::table('form_templates', function (Blueprint $table) {
            $table->dropForeign(['fa_subclass_code']);
            $table->dropColumn('fa_subclass_code');
        });
    }

    public function down(): void
    {
        Schema::table('form_templates', function (Blueprint $table) {
            $table->string('fa_subclass_code', 20)->nullable();
        });

        DB::statement('DROP INDEX IF EXISTS form_template_active_category_unique');
        Schema::dropIfExists('form_template_maintenance_category');
    }
};
