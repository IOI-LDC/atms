<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('maintenance_status')->default('Active')->after('is_active');
            $table->string('maintenance_sub_status')->nullable()->after('maintenance_status');
            $table->string('asset_kind')->default('asset')->after('maintenance_sub_status');
            $table->string('asset_tag', 15)->nullable()->unique()->after('asset_kind');
            $table->timestamp('asset_tag_generated_at')->nullable()->after('asset_tag');
            $table->text('asset_tag_override_reason')->nullable()->after('asset_tag_generated_at');
            $table->string('fa_subclass_code', 20)->nullable()->after('asset_tag_override_reason');
            $table->foreignId('parent_asset_id')->nullable()->after('fa_subclass_code')->constrained('assets')->nullOnDelete();

            $table->index('maintenance_status');
            $table->index('parent_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['maintenance_status']);
            $table->dropIndex(['parent_asset_id']);
            $table->dropForeign(['parent_asset_id']);
            $table->dropColumn([
                'maintenance_sub_status',
                'asset_kind',
                'asset_tag',
                'asset_tag_generated_at',
                'asset_tag_override_reason',
                'fa_subclass_code',
                'parent_asset_id',
            ]);
        });
    }
};
