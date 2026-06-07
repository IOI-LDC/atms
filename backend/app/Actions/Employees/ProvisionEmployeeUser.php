<?php

namespace App\Actions\Employees;

use App\Actions\Auth\ActivateUser;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserActivationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisionEmployeeUser
{
    public function __construct(
        private ActivateUser $activateUserAction
    ) {}

    public function execute(Employee $employee, Role $role): User
    {
        return DB::transaction(function () use ($employee, $role) {
            if ($employee->user()->exists() || User::where('emp_id', $employee->emp_id)->exists()) {
                throw new \DomainException('Employee is already provisioned as a user.');
            }

            $user = User::create([
                'emp_id' => $employee->emp_id,
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'password' => \Illuminate\Support\Facades\Hash::make(Str::random(32)),
                'role_id' => $role->id,
                'is_active' => false,
            ]);

            $token = $this->activateUserAction->issueToken($user);
            $url = url('/activate?token=' . $token);
            $user->notify(new UserActivationNotification($url));

            return $user;
        });
    }
}
