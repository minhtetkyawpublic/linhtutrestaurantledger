<?php

namespace App\Http\Controllers;

use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function overview()
    {
        $sales = Sale::query()
            ->where('is_reversed', false)
            ->whereBetween('sale_at', [today(), today()->endOfDay()]);
        $customerBalances = CustomerLedgerEntry::query()
            ->select('customer_id')
            ->selectRaw('SUM(amount_kyat) as balance')
            ->groupBy('customer_id');
        $customerDebt = DB::query()
            ->fromSub($customerBalances, 'customer_balances')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) as total_debt')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END), 0) as owing_count')
            ->first();
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
            'total_customer_debt' => (int) $customerDebt->total_debt,
            'customers_owe_count' => (int) $customerDebt->owing_count,
            'recent_activity' => $recentActivity,
        ]);
    }
}
