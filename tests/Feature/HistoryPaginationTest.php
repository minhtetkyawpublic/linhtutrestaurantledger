<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoryPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_combined_history_is_database_paginated_and_defaults_to_today(): void
    {
        $user = User::factory()->create();
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'view_sales_history'],
            ['label' => 'View sales history']
        );
        $role = Role::query()->create([
            'name' => 'history-test',
            'display_name' => 'History test',
            'is_system' => false,
        ]);
        $role->permissions()->sync([$permission->id]);
        $user->roles()->sync([$role->id]);

        $customer = Customer::query()->create([
            'name' => 'History Customer',
            'is_active' => true,
            'is_archived' => false,
        ]);

        for ($index = 1; $index <= 4; $index++) {
            Sale::query()->create([
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'invoice_number' => "HISTORY-{$index}",
                'sale_at' => now()->subMinutes($index),
                'total_kyat' => 100 * $index,
                'paid_kyat' => 100 * $index,
            ]);
        }

        for ($index = 1; $index <= 3; $index++) {
            CustomerLedgerEntry::query()->create([
                'customer_id' => $customer->id,
                'actor_user_id' => $user->id,
                'event_type' => $index === 3 ? 'money_lent' : 'customer_paid',
                'amount_kyat' => $index === 3 ? 300 : -100,
                'balance_after_kyat' => 0,
                'reason' => "History entry {$index}",
                'occurred_at' => now()->subMinutes(10 + $index),
            ]);
        }

        Sale::query()->create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'HISTORY-YESTERDAY',
            'sale_at' => now()->subDay(),
            'total_kyat' => 999,
            'paid_kyat' => 999,
        ]);

        $first = $this->actingAs($user)->getJson('/api/histories?per_page=5');
        $first->assertOk()
            ->assertJsonPath('total', 7)
            ->assertJsonPath('per_page', 5)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('filters.range', 'today');

        $second = $this->actingAs($user)->getJson('/api/histories?per_page=5&page=2');
        $second->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(2, 'data');

        $payments = $this->actingAs($user)->getJson('/api/histories?type=customer_paid');
        $payments->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.type', 'customer_paid');
    }
}
