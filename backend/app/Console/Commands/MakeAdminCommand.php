<?php

namespace App\Console\Commands;

use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeAdminCommand extends Command
{
    protected $signature = 'atms:make-admin
        {--name= : Admin user name}
        {--email= : Admin user email}
        {--password= : Admin user password (optional, will prompt if omitted)}';

    protected $description = 'Create the initial ATMS Administrator user';

    public function handle(): int
    {
        $adminRole = Role::where('code', RoleCode::ADMINISTRATOR)->first();

        if (! $adminRole) {
            $this->error('Administrator role not found. Run migrations and seeders first: php artisan migrate --seed');

            return self::FAILURE;
        }

        if (User::where('role_id', $adminRole->id)->where('is_active', true)->whereNotNull('activated_at')->exists()) {
            $this->error('An Administrator user already exists. Use the admin panel to manage users.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?? $this->ask('Administrator name');
        $email = $this->option('email') ?? $this->ask('Administrator email');

        if (! $name || ! $email) {
            $this->error('Name and email are required.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email '{$email}' already exists.");

            return self::FAILURE;
        }

        $password = $this->option('password');

        if (! $password) {
            $password = $this->secret('Administrator password');
        }

        if (! $password || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role_id' => $adminRole->id,
            'is_active' => true,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);

        $this->info("Administrator user '{$user->email}' created successfully.");

        return self::SUCCESS;
    }
}
