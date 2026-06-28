<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-time baseline provisioning of the initial users (+ their employee rows).
 *
 * Runs exactly once per database via `php artisan migrate`. Establishes:
 *   - system@atms.internal   : scheduler/background-job author (inactive)
 *   - service@atms.internal  : M2M token-issuance account (inactive, SERVICE role)
 *   - admin@atms.local       : initial admin (Password123!Y@TR)
 *   - heimo@ldc.com.ly       : administrator (linked to employee emp_id=3)
 *   - S.Rihan@ldc.com.ly     : maintenance_manager (emp_id=60)
 *   - Mohamed.Aldeeb@ldc.com.ly : maintenance_manager (emp_id=37)
 *
 * Uses updateOrInsert so it is safe whether the target DB is fresh or already
 * holds some of these rows (e.g. dev). The three employee users get random
 * passwords generated at run time.
 *
 * NOTE: `admin@atms.local`'s password is hardcoded below and will be stored in
 * version control. Rotate it after first login if this is sensitive.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Baseline provisioning for dev/prod only. Never run during tests: it
        // seeds real user/employee rows (e.g. system@atms.internal) that collide
        // with test fixtures and pollute assertions. Guard keys on the testing
        // env and the dedicated "testing" connection name so it is reliable even
        // when container OS env vars shadow phpunit.xml <env> values (e.g. APP_ENV).
        if (App::environment('testing') || DB::connection()->getName() === 'testing') {
            return;
        }

        $now = now();

        // Ensure the required roles exist (roles table has no timestamps).
        $roleCodes = ['administrator', 'maintenance_manager', 'service'];
        $roleIds = [];
        foreach ($roleCodes as $code) {
            $id = DB::table('roles')->where('code', $code)->value('id');
            if (! $id) {
                $id = DB::table('roles')->insertGetId([
                    'code' => $code,
                    'name' => ucfirst(str_replace('_', ' ', $code)),
                    'description' => $code === 'service'
                        ? 'Non-human role for M2M API tokens. Not assignable to users.'
                        : '',
                ]);
            }
            $roleIds[$code] = $id;
        }

        // system@atms.internal — only insert if missing (preserve any existing row).
        if (! DB::table('users')->where('email', 'system@atms.internal')->exists()) {
            DB::table('users')->insert([
                'name' => 'ATMS System',
                'email' => 'system@atms.internal',
                'password' => Hash::make(Str::random(64)),
                'role_id' => $roleIds['administrator'],
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // service@atms.internal — M2M service account.
        DB::table('users')->updateOrInsert(
            ['email' => 'service@atms.internal'],
            [
                'name' => 'ATMS Service Account',
                'password' => Hash::make(Str::random(32)),
                'role_id' => $roleIds['service'],
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // admin@atms.local — initial admin.
        DB::table('users')->updateOrInsert(
            ['email' => 'admin@atms.local'],
            [
                'name' => 'Admin',
                'password' => Hash::make('Password123!Y@TR'),
                'role_id' => $roleIds['administrator'],
                'is_active' => true,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // Employees + their user accounts.
        $people = [
            ['role' => 'administrator', 'sharepoint_item_id' => '2', 'emp_id' => '3', 'name' => 'Heimo Muckenschnabl', 'email' => 'heimo@ldc.com.ly', 'department' => 'EXC', 'job_title' => 'CEO'],
            ['role' => 'maintenance_manager', 'sharepoint_item_id' => '53', 'emp_id' => '60', 'name' => 'Sohaib Mohammed Rihan', 'email' => 'S.Rihan@ldc.com.ly', 'department' => 'OFS', 'job_title' => 'Field Engineer Trainee'],
            ['role' => 'maintenance_manager', 'sharepoint_item_id' => '33', 'emp_id' => '37', 'name' => 'Mohamed Alie Eldieb', 'email' => 'Mohamed.Aldeeb@ldc.com.ly', 'department' => 'OFS', 'job_title' => 'Field Engineer'],
        ];

        foreach ($people as $p) {
            DB::table('employees')->updateOrInsert(
                ['emp_id' => $p['emp_id']],
                [
                    'sharepoint_item_id' => $p['sharepoint_item_id'],
                    'name' => $p['name'],
                    'email' => $p['email'],
                    'department' => $p['department'],
                    'job_title' => $p['job_title'],
                    'source_is_active' => true,
                    'last_synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $employeePk = DB::table('employees')->where('emp_id', $p['emp_id'])->value('id');

            DB::table('users')->updateOrInsert(
                ['email' => $p['email']],
                [
                    'name' => $p['name'],
                    'password' => Hash::make(Str::random(20)),
                    'role_id' => $roleIds[$p['role']],
                    'employee_id' => $employeePk,
                    'is_active' => true,
                    'activated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (App::environment('testing') || DB::connection()->getName() === 'testing') {
            return;
        }

        DB::table('users')->whereIn('email', [
            'service@atms.internal',
            'admin@atms.local',
            'heimo@ldc.com.ly',
            'S.Rihan@ldc.com.ly',
            'Mohamed.Aldeeb@ldc.com.ly',
        ])->delete();

        DB::table('employees')->whereIn('emp_id', ['3', '60', '37'])->delete();
    }
};
