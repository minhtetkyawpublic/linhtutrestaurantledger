<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin {--email=} {--name=}';

    protected $description = 'Create an administrator using hidden interactive password input';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Administrator name'));
        $email = (string) ($this->option('email') ?: $this->ask('Administrator email'));
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $adminRole = Role::query()->where('name', 'admin')->first();
        if (! $adminRole) {
            $this->error('Admin role is missing. Run the RolesPermissionsSeeder first.');

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_disabled' => false,
            'ui_locale' => 'en',
        ]);
        $user->roles()->attach($adminRole->id);

        AuditLog::query()->create([
            'actor_user_id' => null,
            'action' => 'initial_admin_created',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'changes' => ['email' => $email],
            'reason' => 'Interactive administrator bootstrap',
        ]);

        $this->info('Administrator created successfully.');

        return self::SUCCESS;
    }
}
