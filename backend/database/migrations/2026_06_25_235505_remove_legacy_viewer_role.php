<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The legacy Viewer role was merged into Requester during the scope change.
 * The RoleCode enum and RoleSeeder no longer define `viewer`, but databases
 * seeded before the change still hold a stale `viewer` row — which throws a
 * ValueError as soon as Eloquent casts it to the RoleCode enum (e.g. on
 * GET /admin/roles). Reassign any remaining viewer users to Requester, then
 * drop the orphaned role. Uses the query builder so no enum cast is triggered.
 */
return new class extends Migration
{
    public function up(): void
    {
        $viewer = DB::table('roles')->where('code', 'viewer')->first();
        if (! $viewer) {
            return;
        }

        $requester = DB::table('roles')->where('code', 'requester')->first();
        if ($requester) {
            DB::table('users')
                ->where('role_id', $viewer->id)
                ->update(['role_id' => $requester->id]);
        }

        DB::table('roles')->where('id', $viewer->id)->delete();
    }

    public function down(): void
    {
        // Irreversible: the Viewer role is permanently merged into Requester.
        // Re-creating the row would not restore original user assignments.
    }
};
