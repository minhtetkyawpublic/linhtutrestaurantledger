<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CurryItem;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesLedgerTest extends TestCase
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

    private function createCustomerAndItems(): array
    {
        $item = CurryItem::query()->create([
            'name' => 'Chicken',
            'current_price_kyat' => 500,
            'is_available' => true,
            'display_order' => 1,
            'is_archived' => false,
        ]);

        $customer = Customer::query()->create([
            'name' => 'Aung Aung',
            'phone_number' => '09123456',
            'is_archived' => false,
            'is_active' => true,
            'opening_balance_kyat' => 0,
        ]);

        return [$customer, $item];
    }

    private function latestBalanceFor(Customer $customer): int
    {
        return (int) CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('balance_after_kyat');
    }

    public function test_named_customer_partial_sale_creates_ledger_debt(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);
        [$customer, $item] = $this->createCustomerAndItems();

        $response = $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::now()->toIso8601String(),
            'discount_kyat' => 100,
            'paid_kyat' => 600,
            'items' => [
                ['curry_item_id' => $item->id, 'quantity' => 2],
            ],
        ]);
        $response->assertStatus(201);

        $this->assertSame(300, $this->latestBalanceFor($customer));
    }

    public function test_duplicate_sale_submission_with_idempotency_key_returns_existing_record(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);
        [$customer, $item] = $this->createCustomerAndItems();

        $payload = [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::now()->toIso8601String(),
            'discount_kyat' => 100,
            'paid_kyat' => 600,
            'idempotency_key' => 'sale_key_abc',
            'items' => [
                ['curry_item_id' => $item->id, 'quantity' => 2],
            ],
        ];

        $first = $this->actingAs($user)->postJson('/api/sales', $payload);
        $first->assertStatus(201);
        $firstId = $first->json('id');

        $second = $this->actingAs($user)->postJson('/api/sales', $payload);
        $second->assertStatus(200);
        $this->assertSame($firstId, $second->json('id'));
        $this->assertSame(300, $this->latestBalanceFor($customer));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('customer_ledger_entries', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'sale_created')->count());
    }

    public function test_sale_idempotency_key_rejects_changed_submission_details(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);
        [$customer, $item] = $this->createCustomerAndItems();
        $payload = [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 500,
            'idempotency_key' => 'sale-conflict-key',
            'items' => [['curry_item_id' => $item->id, 'quantity' => 1]],
        ];

        $this->actingAs($user)->postJson('/api/sales', $payload)->assertCreated();
        $payload['paid_kyat'] = 400;
        $this->actingAs($user)->postJson('/api/sales', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('customer_ledger_entries', 1);
    }

    public function test_overpay_creates_negative_balance_for_named_customer(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);
        [$customer, $item] = $this->createCustomerAndItems();

        $response = $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 1500,
            'items' => [
                ['curry_item_id' => $item->id, 'quantity' => 2],
            ],
        ]);
        $response->assertStatus(201);

        $this->assertSame(-500, $this->latestBalanceFor($customer));
    }

    public function test_walk_in_sales_require_fully_paid_no_overpay_or_shortpay(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);
        [, $item] = $this->createCustomerAndItems();

        $shortResponse = $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => null,
            'is_walk_in' => true,
            'sale_at' => Carbon::now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 200,
            'items' => [
                ['curry_item_id' => $item->id, 'quantity' => 1],
            ],
        ]);
        $shortResponse->assertStatus(422);
        $shortResponse->assertJsonValidationErrors('paid_kyat');

        $okResponse = $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => null,
            'is_walk_in' => true,
            'sale_at' => Carbon::now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 500,
            'items' => [
                ['curry_item_id' => $item->id, 'quantity' => 1],
            ],
        ]);
        $okResponse->assertStatus(201);
    }

    public function test_sale_ignores_submitted_unit_price_and_keeps_historical_item_snapshot(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);
        [$customer, $item] = $this->createCustomerAndItems();

        $response = $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 0,
            'items' => [[
                'curry_item_id' => $item->id,
                'quantity' => 2,
                'unit_price_kyat' => 1,
            ]],
        ])->assertCreated();

        $saleItemId = $response->json('items.0.id');
        $this->assertSame(500, (int) $response->json('items.0.unit_price_snapshot_kyat'));
        $this->assertSame(1000, (int) $response->json('items.0.line_total_kyat'));

        $item->update([
            'name' => 'Renamed Curry',
            'current_price_kyat' => 900,
            'is_archived' => true,
        ]);

        $this->assertDatabaseHas('sale_items', [
            'id' => $saleItemId,
            'curry_name_snapshot' => 'Chicken',
            'unit_price_snapshot_kyat' => 500,
            'line_total_kyat' => 1000,
        ]);
    }

    public function test_sale_rejects_a_negative_paid_amount(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);
        [$customer, $item] = $this->createCustomerAndItems();

        $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => -1,
            'items' => [['curry_item_id' => $item->id, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('paid_kyat');
    }

    public function test_sale_loads_all_selected_curries_in_one_query(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);
        [$customer, $firstItem] = $this->createCustomerAndItems();
        $secondItem = CurryItem::query()->create([
            'name' => 'Pork',
            'current_price_kyat' => 700,
            'is_available' => true,
            'display_order' => 2,
            'is_archived' => false,
        ]);
        $curryQueries = 0;
        DB::listen(function ($query) use (&$curryQueries) {
            if (str_contains(strtolower($query->sql), 'curry_items')) {
                $curryQueries++;
            }
        });

        $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 0,
            'items' => [
                ['curry_item_id' => $firstItem->id, 'quantity' => 1],
                ['curry_item_id' => $secondItem->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $this->assertSame(1, $curryQueries);
    }

    public function test_sale_rejects_a_subtotal_above_the_supported_money_limit(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);
        [$customer, $item] = $this->createCustomerAndItems();
        $item->update(['current_price_kyat' => 9000000000000]);

        $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 0,
            'items' => [['curry_item_id' => $item->id, 'quantity' => 2]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('customer_ledger_entries', 0);
    }

    public function test_sale_customer_options_are_bounded_searchable_and_keep_the_selected_customer(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);
        foreach (range(1, 60) as $index) {
            Customer::query()->create([
                'name' => sprintf('Customer %03d', $index),
                'phone_number' => '09'.str_pad((string) $index, 8, '0', STR_PAD_LEFT),
                'is_active' => true,
                'is_archived' => false,
            ]);
        }
        $selected = Customer::query()->where('name', 'Customer 060')->firstOrFail();

        $this->actingAs($user)
            ->getJson('/api/sales/create-options')
            ->assertOk()
            ->assertJsonCount(50, 'customers');

        $this->actingAs($user)
            ->getJson('/api/sales/create-options?customer_q=060')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.id', $selected->id);

        $this->actingAs($user)
            ->getJson("/api/sales/create-options?customer_id={$selected->id}")
            ->assertOk()
            ->assertJsonCount(51, 'customers')
            ->assertJsonPath('customers.0.id', $selected->id);
    }

    public function test_payment_and_loan_operations_create_expected_ledger_deltas(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale', 'record_customer_payment', 'record_money_given_lent']);
        [$customer, $item] = $this->createCustomerAndItems();

        $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 0,
            'items' => [
                ['curry_item_id' => $item->id, 'quantity' => 1],
            ],
        ])->assertStatus(201);
        $this->assertSame(500, $this->latestBalanceFor($customer));

        $payment = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
            'amount_kyat' => 200,
            'reason' => 'Pay by cash',
        ]);
        $payment->assertStatus(201);
        $this->assertSame(300, $this->latestBalanceFor($customer));

        $loan = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/money-lent", [
            'amount_kyat' => 100,
            'reason' => 'Change return',
        ]);
        $loan->assertStatus(201);
        $this->assertSame(400, $this->latestBalanceFor($customer));
    }

    public function test_current_thailand_time_is_accepted_for_customer_money_actions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 15:00:30', 'Asia/Bangkok'));

        try {
            $user = $this->makeUserWithPermissions([
                'record_customer_payment',
                'record_money_given_lent',
                'backdate_sale',
            ]);
            [$customer] = $this->createCustomerAndItems();

            $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
                'amount_kyat' => 200,
                'reason' => 'Thailand current-time payment',
                'occurred_at' => '2026-08-21T15:00',
            ])->assertCreated();

            $this->actingAs($user)->postJson("/api/customers/{$customer->id}/money-lent", [
                'amount_kyat' => 50,
                'reason' => 'Thailand current-time shop payment',
                'occurred_at' => '2026-08-21T15:00',
            ])->assertCreated();

            $this->assertDatabaseCount('customer_ledger_entries', 2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_financial_reason_accepts_the_documented_validation_limit(): void
    {
        $user = $this->makeUserWithPermissions(['record_customer_payment']);
        [$customer] = $this->createCustomerAndItems();
        $reason = str_repeat('R', 500);

        $response = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
            'amount_kyat' => 100,
            'reason' => $reason,
        ]);

        $response->assertCreated()->assertJsonPath('reason', $reason);
        $this->assertDatabaseHas('customer_ledger_entries', [
            'customer_id' => $customer->id,
            'reason' => $reason,
        ]);
    }

    public function test_duplicate_payment_idempotency_key_does_not_change_balance_twice(): void
    {
        $user = $this->makeUserWithPermissions(['record_customer_payment']);
        [$customer] = $this->createCustomerAndItems();
        $payload = [
            'amount_kyat' => 200,
            'reason' => 'Single payment',
            'idempotency_key' => 'payment-once-123',
        ];

        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", $payload)
            ->assertCreated();
        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", $payload)
            ->assertOk();

        $this->assertSame(-200, $this->latestBalanceFor($customer));
        $this->assertDatabaseCount('customer_ledger_entries', 1);
    }

    public function test_payment_idempotency_key_rejects_changed_amount(): void
    {
        $user = $this->makeUserWithPermissions(['record_customer_payment']);
        [$customer] = $this->createCustomerAndItems();
        $payload = [
            'amount_kyat' => 200,
            'reason' => 'Single payment',
            'idempotency_key' => 'payment-conflict-key',
        ];

        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", $payload)->assertCreated();
        $payload['amount_kyat'] = 300;
        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(-200, $this->latestBalanceFor($customer));
        $this->assertDatabaseCount('customer_ledger_entries', 1);
    }

    public function test_ledger_idempotency_key_cannot_be_reused_for_another_customer_or_action(): void
    {
        $user = $this->makeUserWithPermissions(['record_customer_payment', 'record_money_given_lent']);
        $firstCustomer = Customer::query()->create([
            'name' => 'First idempotency customer',
            'is_active' => true,
            'is_archived' => false,
            'opening_balance_kyat' => 0,
        ]);
        $secondCustomer = Customer::query()->create([
            'name' => 'Second idempotency customer',
            'is_active' => true,
            'is_archived' => false,
            'opening_balance_kyat' => 0,
        ]);
        $key = 'shared-ledger-key';

        $this->actingAs($user)->postJson("/api/customers/{$firstCustomer->id}/payments", [
            'amount_kyat' => 100,
            'reason' => 'First action',
            'idempotency_key' => $key,
        ])->assertCreated();

        $this->actingAs($user)->postJson("/api/customers/{$secondCustomer->id}/payments", [
            'amount_kyat' => 200,
            'reason' => 'Wrong customer reuse',
            'idempotency_key' => $key,
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->actingAs($user)->postJson("/api/customers/{$firstCustomer->id}/money-lent", [
            'amount_kyat' => 200,
            'reason' => 'Wrong action reuse',
            'idempotency_key' => $key,
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(-100, $this->latestBalanceFor($firstCustomer));
        $this->assertSame(0, $this->latestBalanceFor($secondCustomer));
        $this->assertDatabaseCount('customer_ledger_entries', 1);
    }

    public function test_backdated_money_entry_recalculates_chronological_running_balances(): void
    {
        $user = $this->makeUserWithPermissions(['record_customer_payment', 'record_money_given_lent', 'backdate_sale']);
        [$customer] = $this->createCustomerAndItems();

        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
            'amount_kyat' => 100,
            'reason' => 'Payment yesterday',
            'occurred_at' => now()->subDay()->toIso8601String(),
        ])->assertCreated();

        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/money-lent", [
            'amount_kyat' => 50,
            'reason' => 'Money two days ago',
            'occurred_at' => now()->subDays(2)->toIso8601String(),
        ])->assertCreated();

        $entries = CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $this->assertSame(50, $entries[0]->balance_after_kyat);
        $this->assertSame(-50, $entries[1]->balance_after_kyat);
        $this->assertSame(-50, $customer->fresh()->currentBalanceKyat());
    }

    public function test_backdated_money_entry_requires_backdate_permission(): void
    {
        $user = $this->makeUserWithPermissions(['record_customer_payment']);
        [$customer] = $this->createCustomerAndItems();

        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
            'amount_kyat' => 100,
            'reason' => 'Unauthorized backdate',
            'occurred_at' => now()->subDay()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

        $this->assertDatabaseCount('customer_ledger_entries', 0);
    }

    public function test_payment_and_loan_entries_can_be_reversed_with_audit_trail(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale', 'record_customer_payment', 'record_money_given_lent', 'correct_reverse_ledger']);
        [$customer, $item] = $this->createCustomerAndItems();

        $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 0,
            'items' => [
                ['curry_item_id' => $item->id, 'quantity' => 1],
            ],
        ])->assertStatus(201);

        $payment = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
            'amount_kyat' => 300,
            'reason' => 'Paid quickly',
        ]);
        $payment->assertStatus(201);
        $paymentEntryId = $payment->json('id');

        $this->assertSame(200, $this->latestBalanceFor($customer));

        $loan = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/money-lent", [
            'amount_kyat' => 100,
            'reason' => 'Returned change',
        ]);
        $loan->assertStatus(201);
        $this->assertSame(300, $this->latestBalanceFor($customer));

        $loanEntryId = $loan->json('id');
        $loanReversal = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/ledger/{$loanEntryId}/reverse", [
            'reason' => 'Undo loan adjustment',
        ]);
        $loanReversal->assertStatus(200);
        $loanReversalId = $loanReversal->json('id');

        $this->assertNotSame($paymentEntryId, $loanReversalId);
        $this->assertSame('ledger_entry_reversed', $loanReversal->json('event_type'));
        $this->assertSame(-100, (int) $loanReversal->json('amount_kyat'));
        $this->assertSame(200, $this->latestBalanceFor($customer));

        $paymentReversal = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/ledger/{$paymentEntryId}/reverse", [
            'reason' => 'Undo payment',
        ]);
        $paymentReversal->assertStatus(200);
        $this->assertSame(300, (int) $paymentReversal->json('amount_kyat'));
        $this->assertSame(500, $this->latestBalanceFor($customer));
    }

    public function test_payment_can_be_corrected_by_reversal_and_replacement_with_complete_history(): void
    {
        $user = $this->makeUserWithPermissions(['record_customer_payment', 'correct_reverse_ledger']);
        [$customer] = $this->createCustomerAndItems();

        $payment = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
            'amount_kyat' => 300,
            'reason' => 'Cash received',
            'note' => 'Original note',
        ])->assertCreated();

        $response = $this->actingAs($user)->postJson(
            "/api/customers/{$customer->id}/ledger/{$payment->json('id')}/correct",
            [
                'amount_kyat' => 125,
                'reason' => 'Correct counting error',
                'note' => 'Corrected note',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('reversal.event_type', 'ledger_entry_reversed')
            ->assertJsonPath('reversal.amount_kyat', 300)
            ->assertJsonPath('replacement.event_type', 'customer_paid')
            ->assertJsonPath('replacement.amount_kyat', -125)
            ->assertJsonPath('replacement.meta.correction_of_entry_id', $payment->json('id'));

        $this->assertSame(-125, $this->latestBalanceFor($customer));
        $this->assertDatabaseHas('customer_ledger_entries', [
            'id' => $payment->json('id'),
            'amount_kyat' => -300,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ledger_entry_corrected',
            'reason' => 'Correct counting error',
        ]);

        $this->actingAs($user)->postJson(
            "/api/customers/{$customer->id}/ledger/{$payment->json('id')}/correct",
            [
                'amount_kyat' => 100,
                'reason' => 'Duplicate correction',
            ]
        )->assertUnprocessable()->assertJsonValidationErrors('ledger_entry');

        $this->assertDatabaseCount('customer_ledger_entries', 3);
    }

    public function test_historical_ledger_correction_requires_backdate_permission(): void
    {
        $user = $this->makeUserWithPermissions(['record_money_given_lent', 'correct_reverse_ledger']);
        [$customer] = $this->createCustomerAndItems();

        $loan = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/money-lent", [
            'amount_kyat' => 100,
            'reason' => 'Money given',
        ])->assertCreated();

        $this->actingAs($user)->postJson(
            "/api/customers/{$customer->id}/ledger/{$loan->json('id')}/correct",
            [
                'amount_kyat' => 150,
                'reason' => 'Backdated correction attempt',
                'occurred_at' => now()->subDay()->toIso8601String(),
            ]
        )->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

        $this->assertDatabaseCount('customer_ledger_entries', 1);
        $this->assertSame(100, $this->latestBalanceFor($customer));
    }

    public function test_opening_balance_adjustments_can_be_reversed_with_audit_trail(): void
    {
        $user = $this->makeUserWithPermissions(['create_edit_customers', 'correct_reverse_ledger']);

        $customer = Customer::query()->create([
            'name' => 'Mya Mya',
            'phone_number' => '0999000',
            'is_archived' => false,
            'is_active' => true,
            'opening_balance_kyat' => 0,
        ]);

        $response = $this->actingAs($user)->putJson("/api/customers/{$customer->id}", [
            'name' => 'Mya Mya',
            'opening_balance_kyat' => 800,
            'opening_balance_reason' => 'Initial opening balance',
        ]);
        $response->assertStatus(200);

        $openingEntry = CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->where('event_type', 'opening_balance_adjustment')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(800, $this->latestBalanceFor($customer));

        $reversal = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/ledger/{$openingEntry->id}/reverse", [
            'reason' => 'Undo opening balance setup',
        ]);

        $reversal->assertStatus(200);
        $this->assertSame('ledger_entry_reversed', $reversal->json('event_type'));
        $this->assertSame(-800, (int) $reversal->json('amount_kyat'));
        $this->assertSame(0, $this->latestBalanceFor($customer));
        $this->assertSame('opening_balance_adjustment', $openingEntry->fresh()->event_type);
    }

    public function test_backdating_requires_permission_and_sales_reversals_are_audited(): void
    {
        [$customer, $item] = $this->createCustomerAndItems();

        $withoutBackdate = $this->makeUserWithPermissions(['create_sale']);
        $this->actingAs($withoutBackdate)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::yesterday()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 500,
            'items' => [['curry_item_id' => $item->id, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('sale_at');

        $user = $this->makeUserWithPermissions(['create_sale', 'view_sales_history', 'edit_sale', 'delete_reverse_sale', 'backdate_sale']);

        $create = $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::yesterday()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 500,
            'items' => [
                ['curry_item_id' => $item->id, 'quantity' => 1],
            ],
        ]);
        $create->assertStatus(201);
        $saleId = $create->json('id');

        $balanceAfterCreate = $this->latestBalanceFor($customer);
        $this->assertSame(0, $balanceAfterCreate);

        $update = $this->actingAs($user)->putJson("/api/sales/{$saleId}", [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::yesterday()->toIso8601String(),
            'note' => 'changed',
            'discount_kyat' => 0,
            'paid_kyat' => 500,
            'reason' => 'Correction',
            'items' => [
                ['curry_item_id' => $item->id, 'quantity' => 2],
            ],
        ]);
        $update->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sale_updated',
            'subject_id' => $saleId,
            'reason' => 'Correction',
        ]);

        $this->assertSame(500, $this->latestBalanceFor($customer));

        $reverse = $this->actingAs($user)->postJson("/api/sales/{$saleId}/reverse", [
            'reason' => 'Customer cancelled',
        ]);
        $reverse->assertStatus(200);
        $this->assertTrue((bool) $reverse->json('is_reversed'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sale_reversed',
            'subject_id' => $saleId,
            'reason' => 'Customer cancelled',
        ]);

        $this->assertSame(0, $this->latestBalanceFor($customer));

        $history = $this->actingAs($user)->getJson('/api/histories?range=yesterday');
        $history->assertOk()
            ->assertJsonPath('data.0.record_id', $saleId)
            ->assertJsonPath('data.0.is_reversed', true);

        $this->actingAs($user)
            ->getJson("/api/histories/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('id', $saleId)
            ->assertJsonPath('is_reversed', true);
    }
}
