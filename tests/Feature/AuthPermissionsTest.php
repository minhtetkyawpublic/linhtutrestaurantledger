<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_session_is_returned_without_a_server_error(): void
    {
        $this->getJson('/api/auth/session')
            ->assertOk()
            ->assertJson([
                'authenticated' => false,
                'user' => null,
                'permissions' => [],
            ])
            ->assertJsonPath('csrf_token', fn ($token) => is_string($token) && strlen($token) === 40);
    }

    public function test_post_login_csrf_token_authorizes_the_next_mutation(): void
    {
        $this->seed(RolesPermissionsSeeder::class);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'ChangeMe123!',
        ])->assertOk();

        $token = $login->json('csrf_token');
        $this->assertIsString($token);

        $this->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/auth/locale', ['ui_locale' => 'my'])
            ->assertOk()
            ->assertJsonPath('ui_locale', 'my');
    }

    public function test_login_returns_permissions_for_active_user(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
            'email' => 'cashier@example.com',
            'is_disabled' => false,
        ]);
        $permission = Permission::create(['name' => 'view_dashboard', 'label' => 'View dashboard']);
        $user->directPermissions()->attach($permission);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'cashier@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'user' => ['email' => 'cashier@example.com'],
            'permissions' => ['view_dashboard'],
        ]);
    }

    public function test_disabled_user_cannot_access_active_routes(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled@example.com',
            'password' => 'password',
            'is_disabled' => true,
        ]);

        $response = $this->actingAs($user)->get('/api/admin/staff');

        $response->assertStatus(302);
        $this->assertGuest();
    }

    public function test_disabled_existing_session_is_logged_out_on_refresh(): void
    {
        $user = User::factory()->create(['is_disabled' => true]);

        $this->actingAs($user)
            ->getJson('/api/auth/session')
            ->assertOk()
            ->assertJsonPath('authenticated', false)
            ->assertJsonPath('user', null)
            ->assertJsonPath('permissions', []);

        $this->assertGuest();
    }

    public function test_permission_is_required_for_staff_management_endpoints(): void
    {
        $user = User::factory()->create([
            'email' => 'viewer@example.com',
            'password' => 'password',
        ]);
        $response = $this->actingAs($user)->getJson('/api/admin/staff');
        $response->assertStatus(403);

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
        $adminRole = Role::query()->create([
            'name' => 'admin2',
            'display_name' => 'Admin 2',
            'is_system' => true,
        ]);
        $manage = Permission::query()->firstOrCreate(
            ['name' => 'manage_staff_and_permissions'],
            ['label' => 'Manage staff and permissions']
        );
        $adminRole->permissions()->attach($manage);
        $admin->roles()->attach($adminRole);

        $response = $this->actingAs($admin)->getJson('/api/admin/staff');
        $response->assertStatus(200);
        $response->assertJsonFragment(['email' => 'admin@example.com']);
    }

    public function test_disabled_users_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'disabled@example.com',
            'password' => 'password',
            'is_disabled' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'disabled@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Account disabled');
    }

    public function test_logged_in_user_can_set_locale_preference(): void
    {
        $user = User::factory()->create([
            'email' => 'locale@example.com',
            'password' => 'password',
            'ui_locale' => 'en',
        ]);

        $response = $this->actingAs($user)->postJson('/api/auth/locale', ['ui_locale' => 'my']);
        $response->assertStatus(200);
        $response->assertJsonPath('ui_locale', 'my');

        $this->assertSame('my', $user->refresh()->ui_locale);
    }

    public function test_admin_can_create_configure_and_reset_staff_with_audit_history(): void
    {
        $manage = Permission::create(['name' => 'manage_staff_and_permissions', 'label' => 'Manage staff']);
        $viewAudit = Permission::create(['name' => 'view_audit_history', 'label' => 'View audit']);
        $createSale = Permission::create(['name' => 'create_sale', 'label' => 'Create sale']);
        $admin = User::factory()->create();
        $admin->directPermissions()->attach([$manage->id, $viewAudit->id]);
        $cashier = Role::create(['name' => 'cashier-test', 'display_name' => 'Cashier']);

        $created = $this->actingAs($admin)->postJson('/api/admin/staff', [
            'name' => 'Test Cashier',
            'email' => 'staff@example.com',
            'password' => 'StrongPass123!',
            'role_ids' => [$cashier->id],
            'permission_ids' => [$createSale->id],
        ])->assertCreated();

        $staffId = $created->json('id');
        $this->assertDatabaseHas('user_role', ['user_id' => $staffId, 'role_id' => $cashier->id]);
        $this->assertDatabaseHas('user_permission', ['user_id' => $staffId, 'permission_id' => $createSale->id]);

        $this->actingAs($admin)->putJson("/api/admin/staff/{$staffId}/password", [
            'password' => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
            'reason' => 'Owner requested reset',
        ])->assertOk();

        $this->actingAs($admin)->getJson('/api/admin/audit-history')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'per_page',
                'total',
            ])
            ->assertJsonPath('per_page', 20)
            ->assertJsonFragment(['action' => 'staff_created'])
            ->assertJsonFragment(['action' => 'staff_password_reset']);
    }
}
