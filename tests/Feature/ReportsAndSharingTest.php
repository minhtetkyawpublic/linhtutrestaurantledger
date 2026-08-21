<?php

namespace Tests\Feature;

use App\Models\CurryItem;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportsAndSharingTest extends TestCase
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

    private function createItems(): array
    {
        $small = CurryItem::query()->create([
            'name' => 'Small curry',
            'current_price_kyat' => 100,
            'is_available' => true,
            'display_order' => 1,
            'is_archived' => false,
        ]);

        $big = CurryItem::query()->create([
            'name' => 'Big curry',
            'current_price_kyat' => 1200,
            'is_available' => true,
            'display_order' => 2,
            'is_archived' => false,
        ]);

        return [$small, $big];
    }

    public function test_sale_detail_is_json_and_removed_receipt_route_is_unavailable(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);

        $item = CurryItem::query()->create([
            'name' => 'Fish curry',
            'current_price_kyat' => 450,
            'is_available' => true,
            'display_order' => 1,
            'is_archived' => false,
        ]);

        $customer = Customer::query()->create([
            'name' => 'Ko Aung',
            'phone_number' => '0999000111',
            'is_archived' => false,
            'is_active' => true,
            'opening_balance_kyat' => 0,
        ]);

        $sale = $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 450,
            'items' => [
                ['curry_item_id' => $item->id, 'quantity' => 1],
            ],
        ]);
        $saleId = $sale->json('id');

        $this->actingAs($user)->get("/api/sales/{$saleId}/receipt")->assertNotFound();

        $historyViewer = $this->makeUserWithPermissions(['view_sales_history']);
        $this->actingAs($historyViewer)
            ->getJson("/api/histories/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('invoice_number', $sale->json('invoice_number'))
            ->assertJsonPath('items.0.curry_name_snapshot', 'Fish curry');
    }

    public function test_customer_ledger_date_filter_is_paginated_and_statement_is_removed(): void
    {
        $user = $this->makeUserWithPermissions(['create_edit_customers', 'record_customer_payment', 'view_customers', 'view_customer_statements', 'backdate_sale']);

        $customer = Customer::query()->create([
            'name' => 'May May',
            'phone_number' => '0999000222',
            'is_archived' => false,
            'is_active' => true,
            'opening_balance_kyat' => 0,
        ]);

        $yesterday = Carbon::yesterday()->toDateString();
        $today = Carbon::today()->toDateString();

        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
            'amount_kyat' => 200,
            'reason' => 'Today payment',
            'occurred_at' => Carbon::now()->toDateTimeString(),
        ]);

        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
            'amount_kyat' => 100,
            'reason' => 'Yesterday payment',
            'occurred_at' => Carbon::yesterday()->toDateTimeString(),
        ]);

        $statement = $this->actingAs($user)->getJson("/api/customers/{$customer->id}/ledger?from={$today}&to={$today}");
        $statement->assertStatus(200);
        $statement->assertJsonPath('total', 1);
        $this->assertSame('customer_paid', $statement->json('data.0.event_type'));
        $this->assertSame($user->name, $statement->json('data.0.actor.name'));
        $this->assertSame([], $statement->json('data.0.reversed_by'));

        $this->actingAs($user)
            ->get("/api/customers/{$customer->id}/statement?from={$today}&to={$today}")
            ->assertNotFound();
    }

    public function test_reports_endpoints_produce_sales_and_top_curries(): void
    {
        $user = $this->makeUserWithPermissions([
            'create_sale',
            'view_reports',
            'delete_reverse_sale',
        ]);

        [$small, $big] = $this->createItems();
        $customer = Customer::query()->create([
            'name' => 'Hla Hla',
            'phone_number' => '0999000333',
            'is_archived' => false,
            'is_active' => true,
            'opening_balance_kyat' => 0,
        ]);

        $createdSale = $this->actingAs($user)->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'is_walk_in' => false,
            'sale_at' => Carbon::now()->toIso8601String(),
            'discount_kyat' => 0,
            'paid_kyat' => 2000,
            'items' => [
                ['curry_item_id' => $small->id, 'quantity' => 10],
                ['curry_item_id' => $big->id, 'quantity' => 1],
            ],
        ])->assertStatus(201);

        $summary = $this->actingAs($user)->getJson('/api/reports/sales-summary?range=today');
        $summary->assertStatus(200);
        $summary->assertJsonPath('total_sales', 2200);
        $summary->assertJsonPath('total_new_sale_debt', 200);
        $summary->assertJsonPath('sales_count', 1);

        $partialOnly = $this->actingAs($user)->getJson("/api/reports/sales-summary?range=today&customer_id={$customer->id}&curry_item_id={$small->id}&paid_status=partially_paid");
        $partialOnly->assertOk()->assertJsonPath('sales_count', 1);

        $fullyPaidOnly = $this->actingAs($user)->getJson('/api/reports/sales-summary?range=today&paid_status=fully_paid');
        $fullyPaidOnly->assertOk()->assertJsonPath('sales_count', 0);

        $tops = $this->actingAs($user)->getJson('/api/reports/top-curries?range=today');
        $tops->assertStatus(200);
        $tops->assertJsonPath('most_sold_curry_by_quantity.curry_item_id', $small->id);
        $tops->assertJsonPath('most_sold_curry_by_value.curry_item_id', $big->id);
        $tops->assertJsonCount(2, 'top10_by_quantity');
        $tops->assertJsonPath('top10_by_value.0.curry_item_id', $big->id);

        $fullyPaidTops = $this->actingAs($user)->getJson('/api/reports/top-curries?range=today&paid_status=fully_paid');
        $fullyPaidTops->assertOk()->assertJsonPath('most_sold_curry_by_quantity', null);

        $balances = $this->actingAs($user)->getJson('/api/reports/customer-balances');
        $balances->assertStatus(200);
        $balances->assertJsonPath('total_outstanding', 200);
        $balances->assertJsonPath('customers_owing_count', 1);

        $this->actingAs($user)->postJson('/api/sales/'.$createdSale->json('id').'/reverse', [
            'reason' => 'Cancelled for report test',
        ])->assertOk();

        $afterReversal = $this->actingAs($user)->getJson('/api/reports/sales-summary?range=today');
        $afterReversal->assertOk();
        $afterReversal->assertJsonPath('sales_count', 0);
    }

    public function test_reversed_customer_money_entries_do_not_inflate_report_totals(): void
    {
        $user = $this->makeUserWithPermissions([
            'view_reports',
            'record_customer_payment',
            'record_money_given_lent',
            'correct_reverse_ledger',
        ]);
        $customer = Customer::query()->create([
            'name' => 'Reversal Report Customer',
            'is_archived' => false,
            'is_active' => true,
            'opening_balance_kyat' => 0,
        ]);

        $payment = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
            'amount_kyat' => 300,
            'reason' => 'Payment to reverse',
        ])->assertCreated();
        $loan = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/money-lent", [
            'amount_kyat' => 500,
            'reason' => 'Loan to reverse',
        ])->assertCreated();

        $before = $this->actingAs($user)->getJson('/api/reports/sales-summary?range=today');
        $before->assertJsonPath('customer_payments_received', 300);
        $before->assertJsonPath('money_lent_or_returned', 500);

        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/ledger/{$payment->json('id')}/reverse", [
            'reason' => 'Payment was entered twice',
        ])->assertOk();
        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/ledger/{$loan->json('id')}/reverse", [
            'reason' => 'Loan was cancelled',
        ])->assertOk();

        $after = $this->actingAs($user)->getJson('/api/reports/sales-summary?range=today');
        $after->assertJsonPath('customer_payments_received', 0);
        $after->assertJsonPath('money_lent_or_returned', 0);
    }

    public function test_corrected_customer_payment_reports_only_the_replacement_amount(): void
    {
        $user = $this->makeUserWithPermissions([
            'view_reports',
            'record_customer_payment',
            'correct_reverse_ledger',
        ]);
        $customer = Customer::query()->create([
            'name' => 'Correction Report Customer',
            'is_archived' => false,
            'is_active' => true,
            'opening_balance_kyat' => 0,
        ]);

        $payment = $this->actingAs($user)->postJson("/api/customers/{$customer->id}/payments", [
            'amount_kyat' => 300,
            'reason' => 'Original amount',
        ])->assertCreated();

        $this->actingAs($user)->postJson(
            "/api/customers/{$customer->id}/ledger/{$payment->json('id')}/correct",
            [
                'amount_kyat' => 125,
                'reason' => 'Correct amount',
            ]
        )->assertOk();

        $summary = $this->actingAs($user)->getJson('/api/reports/sales-summary?range=today');
        $summary->assertOk()
            ->assertJsonPath('customer_payments_received', 125);
    }

    public function test_custom_report_range_requires_both_dates(): void
    {
        $user = $this->makeUserWithPermissions(['view_reports']);

        $this->actingAs($user)
            ->getJson('/api/reports/sales-summary?range=custom')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_range');
    }

    public function test_report_filter_options_are_bounded_and_searchable(): void
    {
        $user = $this->makeUserWithPermissions(['view_reports']);
        foreach (range(1, 60) as $index) {
            Customer::query()->create([
                'name' => sprintf('Report Customer %03d', $index),
                'is_active' => true,
                'is_archived' => false,
            ]);
            CurryItem::query()->create([
                'name' => sprintf('Report Curry %03d', $index),
                'current_price_kyat' => 100,
                'display_order' => $index,
                'is_available' => true,
                'is_archived' => false,
            ]);
        }
        $customer = Customer::query()->where('name', 'Report Customer 060')->firstOrFail();
        $curry = CurryItem::query()->where('name', 'Report Curry 060')->firstOrFail();

        $this->actingAs($user)
            ->getJson('/api/reports/filter-options')
            ->assertOk()
            ->assertJsonCount(50, 'customers')
            ->assertJsonCount(50, 'curries');

        $this->actingAs($user)
            ->getJson('/api/reports/filter-options?customer_q=060&curry_q=060')
            ->assertOk()
            ->assertJsonPath('customers.0.id', $customer->id)
            ->assertJsonPath('curries.0.id', $curry->id);

        $this->actingAs($user)
            ->getJson("/api/reports/filter-options?customer_id={$customer->id}&curry_item_id={$curry->id}")
            ->assertOk()
            ->assertJsonCount(51, 'customers')
            ->assertJsonCount(51, 'curries');
    }
}
