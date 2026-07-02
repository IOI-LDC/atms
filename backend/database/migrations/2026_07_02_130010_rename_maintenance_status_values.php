<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the MaintenanceStatus enum values in storage:
 *   'Active'   -> 'enrolled'
 *   'Inactive' -> 'withdrawn'
 *
 * The column is a plain varchar (not a Postgres enum type), so direct UPDATEs
 * are safe. Explicit ALTER TABLE ... SET DEFAULT is used instead of ->change()
 * to avoid Doctrine DBAL enum-type fragility.
 *
 * NOTE: down() is only guaranteed safe before any NEW rows carrying the renamed
 * values are created post-deploy. Historical audit_logs keep their old values
 * and are intentionally NOT migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE assets SET maintenance_status = 'enrolled' WHERE maintenance_status = 'Active'");
        DB::statement("UPDATE assets SET maintenance_status = 'withdrawn' WHERE maintenance_status = 'Inactive'");
        DB::statement("ALTER TABLE assets ALTER COLUMN maintenance_status SET DEFAULT 'enrolled'");
    }

    public function down(): void
    {
        DB::statement("UPDATE assets SET maintenance_status = 'Active' WHERE maintenance_status = 'enrolled'");
        DB::statement("UPDATE assets SET maintenance_status = 'Inactive' WHERE maintenance_status = 'withdrawn'");
        DB::statement("ALTER TABLE assets ALTER COLUMN maintenance_status SET DEFAULT 'Active'");
    }
};
