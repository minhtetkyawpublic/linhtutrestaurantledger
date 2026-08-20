<?php

namespace Tests\Feature;

use App\Models\CurryCategory;
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

    private function createCategoryAndItems(): array
    {
        $category = CurryCategory::query()->create([
            'name' => 'Curry',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $small = CurryItem::query()->create([
            'curry_category_id' => $category->id,
            'name' => 'Small curry',
            'current_price_kyat' => 100,
            'is_available' => true,
            'display_order' => 1,
            'is_archived' => false,
        ]);

        $big = CurryItem::query()->create([
            'curry_category_id' => $category->id,
            'name' => 'Big curry',
            'current_price_kyat' => 1200,
            'is_available' => true,
            'display_order' => 2,
            'is_archived' => false,
        ]);

        return [$small, $big];
    }

    public function test_receipt_endpoint_returns_pdf(): void
    {
        $user = $this->makeUserWithPermissions(['create_sale']);

        $category = CurryCategory::query()->create([
            'name' => 'One',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $item = CurryItem::query()->create([
            'curry_category_id' => $category->id,
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

        $response = $this->actingAs($user)->get("/api/sales/{$saleId}/receipt");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', "inline; filename=\"receipt-{$sale->json('invoice_number')}.pdf\"");
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('%%EOF', $response->getContent());
        $this->assertGreaterThan(5_000, strlen($response->getContent()));

        $otherCreator = $this->makeUserWithPermissions(['create_sale']);
        $this->actingAs($otherCreator)
            ->get("/api/sales/{$saleId}/receipt")
            ->assertForbidden();

        $historyViewer = $this->makeUserWithPermissions(['view_sales_history']);
        $this->actingAs($historyViewer)
            ->get("/api/sales/{$saleId}/receipt")
            ->assertOk();
    }

    public function test_customer_ledger_date_filter_and_statement_pdf(): void
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
        $statement->assertJsonCount(1);
        $this->assertSame('customer_paid', $statement->json('0.event_type'));
        $this->assertSame($user->name, $statement->json('0.actor.name'));
        $this->assertSame([], $statement->json('0.reversed_by'));

        $pdf = $this->actingAs($user)->get("/api/customers/{$customer->id}/statement?from={$today}&to={$today}");
        $pdf->assertStatus(200);
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('%%EOF', $pdf->getContent());
        $this->assertGreaterThan(5_000, strlen($pdf->getContent()));
    }

    public function test_reports_endpoints_produce_sales_and_top_curries(): void
    {
        $user = $this->makeUserWithPermissions([
            'create_sale',
            'view_reports',
            'delete_reverse_sale',
        ]);

        [$small, $big] = $this->createCategoryAndItems();
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
        $balances->assertJsonPath('customers_who_owe_shop.0.customer_id', $customer->id);

        $this->actingAs($user)->postJson('/api/sales/'.$createdSale->json('id').'/reverse', [
            'reason' => 'Cancelled for report test',
        ])->assertOk();

        $afterReversal = $this->actingAs($user)->getJson('/api/reports/sales-summary?range=today');
        $afterReversal->assertOk();
        $afterReversal->assertJsonPath('sales_count', 0);
        $afterReversal->assertJsonPath('reversed_sales_count', 1);
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
        $before->assertJsonPath('reversed_ledger_entries_count', 0);

        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/ledger/{$payment->json('id')}/reverse", [
            'reason' => 'Payment was entered twice',
        ])->assertOk();
        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/ledger/{$loan->json('id')}/reverse", [
            'reason' => 'Loan was cancelled',
        ])->assertOk();

        $after = $this->actingAs($user)->getJson('/api/reports/sales-summary?range=today');
        $after->assertJsonPath('customer_payments_received', 0);
        $after->assertJsonPath('money_lent_or_returned', 0);
        $after->assertJsonPath('reversed_ledger_entries_count', 2);
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
            ->assertJsonPath('customer_payments_received', 125)
            ->assertJsonPath('reversed_ledger_entries_count', 1);
    }
}
