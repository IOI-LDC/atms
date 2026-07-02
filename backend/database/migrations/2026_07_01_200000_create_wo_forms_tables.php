<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('fa_subclass_code', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('fa_subclass_code')
                ->references('fa_subclass_code')
                ->on('fa_subclass_type_codes')
                ->cascadeOnDelete();
        });

        // Only one active template may exist per fa_subclass_code. The partial
        // unique index is the backstop; the controller validation returns a
        // clean 422 first. Valid on pgsql (prod + tests).
        DB::statement(
            'CREATE UNIQUE INDEX form_templates_active_subclass_unique '
            .'ON form_templates (fa_subclass_code) WHERE is_active = true'
        );

        Schema::create('form_template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('label');
            $table->string('field_type');
            $table->boolean('has_pre_post')->default(false);
            $table->string('unit')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('work_order_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->unique()->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('form_template_id')->nullable()->constrained('form_templates')->nullOnDelete();
            $table->timestamp('snapshotted_at')->nullable();
            $table->timestamp('sync_dismissed_at')->nullable();
            $table->timestamps();
        });

        // Self-contained snapshot: metadata is copied in so captured values
        // survive template-field deletion (soft FK is nullOnDelete).
        Schema::create('work_order_form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_form_id')->constrained('work_order_forms')->cascadeOnDelete();
            $table->foreignId('form_template_field_id')->nullable()->constrained('form_template_fields')->nullOnDelete();
            $table->uuid('uuid');
            $table->string('label');
            $table->string('field_type');
            $table->boolean('has_pre_post')->default(false);
            $table->string('unit')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('pre_value')->nullable();
            $table->string('post_value')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['work_order_form_id', 'uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_form_fields');
        Schema::dropIfExists('work_order_forms');
        Schema::dropIfExists('form_template_fields');

        DB::statement('DROP INDEX IF EXISTS form_templates_active_subclass_unique');

        Schema::dropIfExists('form_templates');
    }
};
