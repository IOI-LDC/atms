<?php

use App\Actions\Employees\ProvisionEmployeeUser;
use App\Enums\RoleCode;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Provision ATMS logins for two OFS staff as Maintenance Managers.
 *
 *   Aeman Faraj Eddali            emp 38   A.Eddali@ldc.com.ly
 *   Mohammed Nasreddin Abushaala  emp 97   M.Abushaala@ldc.com.ly
 *
 * Runs through ProvisionEmployeeUser so each user gets an activation token and
 * an activation email, exactly as the Admin UI would. The notification is
 * ShouldQueueAfterCommit, so it is written to `jobs` and delivered by the queue
 * worker — this migration never blocks on Microsoft Graph and a mail outage
 * cannot fail the deploy.
 *
 * Idempotent: an already-provisioned employee is skipped, so re-running against
 * an environment that has these users is a no-op.
 *
 * ⚠️ Sends real mail wherever ACCOUNT_EMAIL_TRANSPORT=graph.
 */
return new class extends Migration
{
    /**
     * @var array<int, array<string, string>>
     */
    private array $people = [
        [
            'emp_id' => '38',
            'sharepoint_item_id' => '34',
            'name' => 'Aeman Faraj Eddali',
            'email' => 'A.Eddali@ldc.com.ly',
            'department' => 'OFS',
            'job_title' => 'Field Engineer',
        ],
        [
            'emp_id' => '97',
            'sharepoint_item_id' => '93',
            'name' => 'Mohammed Nasreddin Abushaala',
            'email' => 'M.Abushaala@ldc.com.ly',
            'department' => 'OFS',
            'job_title' => 'Field Engineer Trainee',
        ],
    ];

    public function up(): void
    {
        $role = Role::where('code', RoleCode::MAINTENANCE_MANAGER)->first();

        // A fresh database has no roles yet — migrations run before seeders, and
        // the test suite migrates into an empty schema. Nothing to provision
        // into, so return quietly rather than throwing and breaking `migrate`.
        if (! $role) {
            return;
        }

        $action = app(ProvisionEmployeeUser::class);

        foreach ($this->people as $person) {
            // Already has a login? Nothing to do — keep this re-runnable.
            $alreadyProvisioned = User::where('emp_id', $person['emp_id'])
                ->orWhereRaw('LOWER(email) = ?', [strtolower($person['email'])])
                ->exists();

            if ($alreadyProvisioned) {
                continue;
            }

            // The HR sync owns the employees table, but it may not have reached
            // these two yet. Match on the immutable emp_id so an existing synced
            // row is reused rather than duplicated.
            $employee = Employee::firstOrCreate(
                ['emp_id' => $person['emp_id']],
                [
                    'sharepoint_item_id' => $person['sharepoint_item_id'],
                    'name' => $person['name'],
                    'email' => $person['email'],
                    'department' => $person['department'],
                    'job_title' => $person['job_title'],
                    'source_is_active' => true,
                    'last_synced_at' => now(),
                ],
            );

            $action->execute($employee, $role);
        }
    }

    /**
     * Deliberately does not delete the users.
     *
     * Once someone has logged in they own audit entries, maintenance requests
     * and work orders; deleting the row on a rollback would either fail on a
     * foreign key or destroy history. Removing a person is an administrative
     * decision, so use Admin → Users → Deactivate instead.
     */
    public function down(): void
    {
        //
    }
};
