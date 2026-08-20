<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Sale;

class DashboardController extends Controller
{
    public function overview()
    {
        $sales = Sale::query()
            ->where('is_reversed', false)
            ->whereBetween('sale_at', [today(), today()->endOfDay()]);
        $customerBalances = Customer::query()
            ->get()
            ->map(fn (Customer $customer) => $customer->currentBalanceKyat());
        $recentActivity = CustomerLedgerEntry::query()
            ->with('customer:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get([
                'id',
                'customer_id',
                'event_type',
                'amount_kyat',
                'balance_after_kyat',
                'occurred_at',
            ]);

        return response()->json([
            'total_sales' => (int) (clone $sales)->sum('total_kyat'),
            'sales_count' => (int) (clone $sales)->count(),
            'total_customer_debt' => (int) $customerBalances->filter(fn (int $balance) => $balance > 0)->sum(),
            'customers_owe_count' => $customerBalances->filter(fn (int $balance) => $balance > 0)->count(),
            'recent_activity' => $recentActivity,
        ]);
    }
}
