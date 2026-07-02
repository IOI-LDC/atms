<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes the MaintenanceSubStatus enum values in storage to lower-case:
 *   'Installed' -> 'installed', 'Ready' -> 'ready', 'LIH' -> 'lih',
 *   'DBR' -> 'dbr', 'Disposed' -> 'disposed', 'Scrapped' -> 'scrapped',
 *   'Other' -> 'other'
 *
 * The column is a nullable varchar (default null) — no default change. On the
 * current live data every row is NULL, so these UPDATEs affect 0 rows; they are
 * included for correctness against seeds / any future PascalCase data. Historical
 * audit_logs keep their old values and are intentionally NOT migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE assets SET maintenance_sub_status = 'installed' WHERE maintenance_sub_status = 'Installed'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'ready' WHERE maintenance_sub_status = 'Ready'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'lih' WHERE maintenance_sub_status = 'LIH'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'dbr' WHERE maintenance_sub_status = 'DBR'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'disposed' WHERE maintenance_sub_status = 'Disposed'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'scrapped' WHERE maintenance_sub_status = 'Scrapped'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'other' WHERE maintenance_sub_status = 'Other'");
    }

    public function down(): void
    {
        DB::statement("UPDATE assets SET maintenance_sub_status = 'Installed' WHERE maintenance_sub_status = 'installed'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'Ready' WHERE maintenance_sub_status = 'ready'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'LIH' WHERE maintenance_sub_status = 'lih'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'DBR' WHERE maintenance_sub_status = 'dbr'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'Disposed' WHERE maintenance_sub_status = 'disposed'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'Scrapped' WHERE maintenance_sub_status = 'scrapped'");
        DB::statement("UPDATE assets SET maintenance_sub_status = 'Other' WHERE maintenance_sub_status = 'other'");
    }
};
