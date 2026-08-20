<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissionNames = [
            ['name' => 'view_dashboard', 'label' => 'View dashboard'],
            ['name' => 'create_sale', 'label' => 'Create sale'],
            ['name' => 'view_sales_history', 'label' => 'View sales history'],
            ['name' => 'backdate_sale', 'label' => 'Backdate sale'],
            ['name' => 'edit_sale', 'label' => 'Edit sale'],
            ['name' => 'delete_reverse_sale', 'label' => 'Delete or reverse sale'],
            ['name' => 'view_customers', 'label' => 'View customers'],
            ['name' => 'create_edit_customers', 'label' => 'Create and edit customers'],
            ['name' => 'record_customer_payment', 'label' => 'Record customer payment'],
            ['name' => 'record_money_given_lent', 'label' => 'Record money lent/returned'],
            ['name' => 'correct_reverse_ledger', 'label' => 'Correct or reverse ledger'],
            ['name' => 'view_customer_statements', 'label' => 'View customer statements'],
            ['name' => 'view_reports', 'label' => 'View reports'],
            ['name' => 'manage_curry_items', 'label' => 'Manage curry items'],
            ['name' => 'manage_staff_and_permissions', 'label' => 'Manage staff and permissions'],
            ['name' => 'view_audit_history', 'label' => 'View audit history'],
        ];

        $permissions = collect($permissionNames)->map(function (array $permission) {
            return Permission::query()->updateOrCreate(
                ['name' => $permission['name']],
                ['label' => $permission['label']]
            );
        });

        $adminPermissions = $permissions;
        $cashierPermissionNames = ['create_sale', 'view_sales_history', 'view_customers', 'create_edit_customers', 'record_customer_payment', 'record_money_given_lent', 'view_customer_statements', 'view_reports', 'view_dashboard'];
        $viewerPermissionNames = ['view_dashboard', 'view_sales_history', 'view_customers', 'view_customer_statements', 'view_reports'];

        $adminRole = Role::query()->updateOrCreate([
            'name' => 'admin',
        ], [
            'display_name' => 'Admin',
            'is_system' => true,
        ]);

        $cashierRole = Role::query()->updateOrCreate([
            'name' => 'cashier',
        ], [
            'display_name' => 'Cashier',
            'is_system' => true,
        ]);

        $viewerRole = Role::query()->updateOrCreate([
            'name' => 'viewer',
        ], [
            'display_name' => 'Viewer',
            'is_system' => true,
        ]);

        $adminRole->permissions()->sync($adminPermissions->pluck('id'));
        $cashierRole->permissions()->sync(
            Permission::query()->whereIn('name', $cashierPermissionNames)->pluck('id')
        );
        $viewerRole->permissions()->sync(
            Permission::query()->whereIn('name', $viewerPermissionNames)->pluck('id')
        );

        // A known default password must never be created or reset in production.
        // Production administrators are created with the interactive
        // `php artisan app:create-admin` command after roles are seeded.
        if (app()->environment(['local', 'testing'])) {
            $adminUser = User::query()->firstOrCreate([
                'email' => env('LOCAL_ADMIN_EMAIL', 'admin@example.com'),
            ], [
                'name' => 'Administrator',
                'password' => Hash::make(env('LOCAL_ADMIN_PASSWORD', 'ChangeMe123!')),
                'is_disabled' => false,
                'ui_locale' => 'en',
            ]);

            $adminUser->roles()->syncWithoutDetaching([$adminRole->id]);
        }
    }
}
