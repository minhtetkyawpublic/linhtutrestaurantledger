<?php

namespace Tests\Feature;

use App\Models\CurryCategory;
use App\Models\CurryItem;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PermissionAndReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['password' => 'password']);

        $role = Role::query()->create([
            'name' => 'temp_'.random_int(1000, 9999),
            'display_name' => 'Temp role',
            'is_system' => false,
        ]);

        $permissionIds = Permission::query()
            ->whereIn('name', $permissionNames)
            ->pluck('id')
            ->toArray();

        if (count($permissionIds) !== count($permissionNames)) {
            $permissionIds = [];
            foreach ($permissionNames as $permissionName) {
                $permissionIds[] = Permission::query()->firstOrCreate(
                    ['name' => $permissionName],
                    ['label' => $permissionName]
                )->id;
            }
        }

        $role->permissions()->sync($permissionIds);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    public function test_reports_endpoints_are_permission_gated(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);

        $this->actingAs($user)->getJson('/api/reports/sales-summary')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/reports/top-curries')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/reports/customer-balances')->assertStatus(403);
        $this->actingAs($user)->getJson('/api/reports/filter-options')->assertStatus(403);

        $admin = $this->makeUserWithPermissions(['view_reports']);
        $archivedCategory = CurryCategory::query()->create([
            'name' => 'Archived report category',
            'is_active' => false,
        ]);
        $archivedCurry = CurryItem::query()->create([
            'name' => 'Archived report curry',
            'current_price_kyat' => 500,
            'curry_category_id' => $archivedCategory->id,
            'is_archived' => true,
        ]);
        $archivedCustomer = Customer::query()->create([
            'name' => 'Archived report customer',
            'is_active' => false,
            'is_archived' => true,
            'opening_balance_kyat' => 0,
        ]);

        $this->actingAs($admin)->getJson('/api/reports/sales-summary')->assertStatus(200);
        $this->actingAs($admin)->getJson('/api/reports/top-curries')->assertStatus(200);
        $this->actingAs($admin)->getJson('/api/reports/customer-balances')->assertStatus(200);
        $this->actingAs($admin)->getJson('/api/reports/filter-options')
            ->assertOk()
            ->assertJsonStructure(['customers', 'categories', 'curries'])
            ->assertJsonFragment(['id' => $archivedCustomer->id, 'name' => 'Archived report customer'])
            ->assertJsonFragment(['id' => $archivedCategory->id, 'name' => 'Archived report category'])
            ->assertJsonFragment(['id' => $archivedCurry->id, 'name' => 'Archived report curry']);
    }

    public function test_recent_customer_activity_requires_dashboard_permission(): void
    {
        $viewer = $this->makeUserWithPermissions(['view_customers']);
        $this->actingAs($viewer)
            ->getJson('/api/dashboard')
            ->assertForbidden();

        $dashboardUser = $this->makeUserWithPermissions(['view_dashboard']);
        $customer = Customer::query()->create([
            'name' => 'Dashboard Customer',
            'is_active' => true,
            'is_archived' => false,
            'opening_balance_kyat' => 0,
        ]);
        CustomerLedgerEntry::query()->create([
            'customer_id' => $customer->id,
            'actor_user_id' => $dashboardUser->id,
            'event_type' => 'customer_paid',
            'amount_kyat' => -250,
            'balance_after_kyat' => -250,
            'occurred_at' => now(),
        ]);
        $this->actingAs($dashboardUser)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('recent_activity.0.event_type', 'customer_paid')
            ->assertJsonPath('recent_activity.0.customer.name', 'Dashboard Customer')
            ->assertJsonPath('total_customer_debt', 0)
            ->assertJsonPath('customers_owe_count', 0);
    }

    public function test_statement_endpoints_respect_customer_statement_permission(): void
    {
        $customer = Customer::query()->create([
            'name' => 'Permission Customer',
            'phone_number' => '0999000333',
            'is_archived' => false,
            'is_active' => true,
            'opening_balance_kyat' => 0,
        ]);

        $user = $this->makeUserWithPermissions(['view_customers']);
        $today = Carbon::today()->toDateString();

        $this->actingAs($user)->getJson("/api/customers/{$customer->id}/statement?from={$today}&to={$today}")
            ->assertStatus(403);

        $allowed = $this->makeUserWithPermissions(['view_customers', 'view_customer_statements']);
        $this->actingAs($allowed)->getJson("/api/customers/{$customer->id}/statement?from={$today}&to={$today}")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_sensitive_mutations_are_each_denied_without_their_permission(): void
    {
        $viewer = $this->makeUserWithPermissions(['view_customers', 'view_sales_history']);
        $viewerRole = $viewer->roles()->firstOrFail();
        $customer = Customer::query()->create([
            'name' => 'Protected Customer',
            'is_active' => true,
            'is_archived' => false,
            'opening_balance_kyat' => 0,
        ]);
        $category = CurryCategory::query()->create(['name' => 'Protected Category']);
        $item = CurryItem::query()->create([
            'name' => 'Protected Curry',
            'current_price_kyat' => 1000,
            'curry_category_id' => $category->id,
        ]);
        $sale = Sale::query()->create([
            'user_id' => $viewer->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'PERMISSION-1',
            'sale_at' => now(),
            'is_walk_in' => false,
        ]);
        $entry = CustomerLedgerEntry::query()->create([
            'customer_id' => $customer->id,
            'actor_user_id' => $viewer->id,
            'event_type' => 'customer_paid',
            'amount_kyat' => -100,
            'balance_after_kyat' => -100,
            'occurred_at' => now(),
        ]);

        $this->actingAs($viewer)->postJson('/api/customers', [])->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/customers/{$customer->id}", [])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/customers/{$customer->id}/payments", [])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/customers/{$customer->id}/money-lent", [])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/customers/{$customer->id}/ledger/{$entry->id}/correct", [])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/customers/{$customer->id}/ledger/{$entry->id}/reverse", [])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/sales', [])->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/sales/create-options')->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/sales/edit-options')->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/sales/{$sale->id}", [])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/sales/{$sale->id}/reverse", [])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/curry-categories', [])->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/curry-categories/{$category->id}", [])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/curry-items', [])->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/curry-items/{$item->id}", [])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/curry-items/{$item->id}/archive", [])->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/admin/roles')->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/admin/permissions')->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/admin/staff')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/admin/staff', [])->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/admin/staff/{$viewer->id}", [])->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/admin/staff/{$viewer->id}/password", [])->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/admin/roles/{$viewerRole->id}/permissions", [])->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/admin/users/{$viewer->id}/disabled", [])->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/admin/audit-history')->assertForbidden();
    }

    public function test_create_sale_options_do_not_require_customer_management_permission(): void
    {
        $creator = $this->makeUserWithPermissions(['create_sale']);
        Customer::query()->create([
            'name' => 'Sale Option Customer',
            'is_active' => true,
            'is_archived' => false,
            'opening_balance_kyat' => 0,
        ]);

        $this->actingAs($creator)
            ->getJson('/api/sales/create-options')
            ->assertOk()
            ->assertJsonPath('customers.0.name', 'Sale Option Customer')
            ->assertJsonStructure(['customers', 'curries']);
    }
}
