<?php

namespace Tests\Feature;

use App\Models\CurryItem;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurryCustomerTest extends TestCase
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

    public function test_curry_items_can_be_created_and_archived_by_authorized_users(): void
    {
        $user = $this->makeUserWithPermissions(['manage_curry_items']);

        $response = $this->actingAs($user)->postJson('/api/curry-items', [
            'name' => 'Chicken Curry',
            'current_price_kyat' => 1200,
            'is_available' => true,
        ]);
        $response->assertStatus(201);
        $response->assertJsonPath('name', 'Chicken Curry');

        $itemId = $response->json('id');
        $archive = $this->actingAs($user)->postJson("/api/curry-items/{$itemId}/archive");
        $archive->assertStatus(200);

        $this->assertTrue((bool) CurryItem::findOrFail($itemId)->is_archived);
    }

    public function test_removed_curry_category_endpoints_are_not_available(): void
    {
        $authorized = $this->makeUserWithPermissions(['manage_curry_items']);

        $this->actingAs($authorized)->getJson('/api/curry-categories')->assertNotFound();
        $this->actingAs($authorized)->postJson('/api/curry-categories', ['name' => 'Breakfast'])->assertNotFound();
    }

    public function test_customers_can_be_created_with_opening_balance_reason_and_searched_by_name_or_phone(): void
    {
        $user = $this->makeUserWithPermissions([
            'create_edit_customers',
            'view_customers',
        ]);

        $response = $this->actingAs($user)->postJson('/api/customers', [
            'name' => 'Alice',
            'phone_number' => '091234567',
            'opening_balance_kyat' => 5000,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('opening_balance_reason');

        $this->actingAs($user)->postJson('/api/customers', [
            'name' => 'Alice',
            'phone_number' => '091234567',
            'opening_balance_kyat' => 5000,
            'opening_balance_reason' => 'carried over',
        ])->assertStatus(201);

        $list = $this->actingAs($user)->getJson('/api/customers?q=Alice');
        $list->assertStatus(200);
        $list->assertJsonPath('total', 1);
        $list->assertJsonFragment(['name' => 'Alice']);

        $phoneSearch = $this->actingAs($user)->getJson('/api/customers?q=091234');
        $phoneSearch->assertStatus(200);
        $phoneSearch->assertJsonFragment(['name' => 'Alice']);
    }

    public function test_every_opening_balance_change_requires_a_new_reason(): void
    {
        $user = $this->makeUserWithPermissions(['create_edit_customers']);
        $customer = Customer::query()->create([
            'name' => 'Opening Balance Customer',
            'opening_balance_kyat' => 500,
            'opening_balance_reason' => 'Imported balance',
            'is_active' => true,
            'is_archived' => false,
        ]);

        $payload = [
            'name' => $customer->name,
            'phone_number' => null,
            'address_or_note' => null,
            'opening_balance_kyat' => 0,
            'opening_balance_reason' => '',
            'is_archived' => false,
        ];

        $this->actingAs($user)
            ->putJson("/api/customers/{$customer->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opening_balance_reason');

        $payload['opening_balance_reason'] = 'Corrected imported balance';
        $this->actingAs($user)
            ->putJson("/api/customers/{$customer->id}", $payload)
            ->assertOk();

        $this->assertDatabaseHas('customer_ledger_entries', [
            'customer_id' => $customer->id,
            'event_type' => 'opening_balance_adjustment',
            'amount_kyat' => -500,
            'reason' => 'Corrected imported balance',
        ]);
    }
}
