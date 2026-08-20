<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_seeder_does_not_reset_an_existing_local_admin_password(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'OwnerChosenPassword123!',
        ]);

        $this->app->make(RolesPermissionsSeeder::class)->run();

        $this->assertTrue(Hash::check('OwnerChosenPassword123!', $admin->fresh()->password));
    }

    public function test_roles_seeder_does_not_create_default_admin_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->app->make(RolesPermissionsSeeder::class)->run();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
        $this->assertDatabaseHas('roles', ['name' => 'admin']);
    }

    public function test_interactive_command_creates_admin_without_password_argument(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $this->artisan('app:create-admin', [
            '--email' => 'owner@example.com',
            '--name' => 'Restaurant Owner',
        ])
            ->expectsQuestion('Password (minimum 12 characters)', 'OwnerChosenPassword123!')
            ->expectsQuestion('Confirm password', 'OwnerChosenPassword123!')
            ->expectsOutput('Administrator created successfully.')
            ->assertSuccessful();

        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue($owner->roles()->where('name', 'admin')->exists());
        $this->assertTrue(Hash::check('OwnerChosenPassword123!', $owner->password));
    }
}
