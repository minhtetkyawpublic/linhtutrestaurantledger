<?php

namespace App\Http\Controllers;

use App\Models\CurryItem;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function filterOptions()
    {
        $customers = Customer::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'is_archived']);
        $customers->each->setAppends([]);

        return response()->json([
            'customers' => $customers,
            'curries' => CurryItem::query()
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(['id', 'name', 'is_archived']),
        ]);
    }

    public function salesSummary(Request $request)
    {
        $filters = $this->reportRange($request);
        $salesQuery = $this->salesQuery($filters, $request);
        $salesQuery = $this->applyPaidStatusFilter($salesQuery, $request->query('paid_status'));
        $totals = $salesQuery
            ->reorder()
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(total_kyat), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(discount_kyat), 0) as total_discounts')
            ->selectRaw('COALESCE(SUM(paid_kyat), 0) as total_paid')
            ->selectRaw('COALESCE(SUM(CASE WHEN unpaid_kyat > 0 THEN unpaid_kyat ELSE 0 END), 0) as total_new_debt')
            ->first();

        $customerPayments = (int) $this->ledgerSumForFilters(
            CustomerLedgerEntry::query(),
            $request,
            'customer_paid',
            $filters
        );

        $moneyLent = (int) $this->ledgerSumForFilters(
            CustomerLedgerEntry::query(),
            $request,
            'money_lent',
            $filters
        );

        $cancelledCount = (int) Sale::query()
            ->where('is_reversed', true)
            ->whereBetween('sale_at', [$filters['from'], $filters['to']])
            ->when($request->filled('customer_id'), function ($query) use ($request) {
                $query->where('customer_id', (int) $request->query('customer_id'));
            })
            ->when($request->filled('curry_item_id'), function ($query) use ($request) {
                $query->whereHas('items', function ($itemsQuery) use ($request) {
                    if ($request->filled('curry_item_id')) {
                        $itemsQuery->where('curry_item_id', (int) $request->query('curry_item_id'));
                    }
                });
            })
            ->count();

        $reversedLedgerCount = (int) CustomerLedgerEntry::query()
            ->where('event_type', 'ledger_entry_reversed')
            ->whereBetween('occurred_at', [$filters['from'], $filters['to']])
            ->when($request->filled('customer_id'), function ($query) use ($request) {
                $query->where('customer_id', (int) $request->query('customer_id'));
            })
            ->count();

        return response()->json([
            'period' => [
                'from' => $filters['from']->toIso8601String(),
                'to' => $filters['to']->toIso8601String(),
            ],
            'total_sales' => (int) $totals->total_sales,
            'total_discounts' => (int) $totals->total_discounts,
            'total_paid_at_sale' => (int) $totals->total_paid,
            'total_new_sale_debt' => (int) $totals->total_new_debt,
            'customer_payments_received' => $customerPayments,
            'money_lent_or_returned' => $moneyLent,
            'sales_count' => (int) $totals->sales_count,
            'reversed_sales_count' => $cancelledCount,
            'reversed_ledger_entries_count' => $reversedLedgerCount,
            'paid_status' => $request->query('paid_status'),
        ]);
    }

    public function customerBalances()
    {
        $balances = CustomerLedgerEntry::query()
            ->select('customer_id')
            ->selectRaw('SUM(amount_kyat) as balance')
            ->groupBy('customer_id');

        $totals = DB::query()
            ->fromSub($balances, 'customer_balances')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) as total_outstanding')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance < 0 THEN ABS(balance) ELSE 0 END), 0) as total_shop_owes')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END), 0) as customers_owing_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance < 0 THEN 1 ELSE 0 END), 0) as shop_owing_count')
            ->first();

        return response()->json([
            'total_outstanding' => (int) $totals->total_outstanding,
            'total_shop_owes' => (int) $totals->total_shop_owes,
            'customers_owing_count' => (int) $totals->customers_owing_count,
            'shop_owing_count' => (int) $totals->shop_owing_count,
        ]);
    }

    public function topCurries(Request $request)
    {
        $filters = $this->reportRange($request);
        $query = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('curry_items', 'curry_items.id', '=', 'sale_items.curry_item_id')
            ->whereNotNull('sale_items.curry_item_id')
            ->where('sales.is_reversed', false)
            ->whereBetween('sales.sale_at', [$filters['from'], $filters['to']])
            ->when($request->filled('customer_id'), fn ($builder) => $builder->where('sales.customer_id', (int) $request->query('customer_id')))
            ->when($request->filled('curry_item_id'), fn ($builder) => $builder->where('sale_items.curry_item_id', (int) $request->query('curry_item_id')));

        $query = match ($request->query('paid_status')) {
            'fully_paid' => $query->where('sales.unpaid_kyat', '<=', 0),
            'partially_paid' => $query->where('sales.unpaid_kyat', '>', 0)->where('sales.paid_kyat', '>', 0),
            'unpaid' => $query->where('sales.paid_kyat', 0),
            default => $query,
        };

        $query->groupBy('sale_items.curry_item_id')
            ->select('sale_items.curry_item_id')
            ->selectRaw('COALESCE(MAX(curry_items.name), MAX(sale_items.curry_name_snapshot)) as name')
            ->selectRaw('SUM(sale_items.quantity) as quantity')
            ->selectRaw('SUM(sale_items.line_total_kyat) as value');

        $byQuantity = (clone $query)->orderByDesc('quantity')->limit(10)->get();
        $byValue = (clone $query)->orderByDesc('value')->limit(10)->get();

        return response()->json([
            'most_sold_curry_by_quantity' => $this->curryStat($byQuantity->first(), 'quantity'),
            'most_sold_curry_by_value' => $this->curryStat($byValue->first(), 'value'),
            'top10_by_quantity' => $byQuantity->map(fn ($row) => $this->curryStat($row, 'quantity'))->values(),
            'top10_by_value' => $byValue->map(fn ($row) => $this->curryStat($row, 'value'))->values(),
        ]);
    }

    private function curryStat(?object $row, string $metric): ?array
    {
        if (! $row) {
            return null;
        }

        return [
            'curry_item_id' => (int) $row->curry_item_id,
            'name' => $row->name,
            'metric' => $metric,
            'value' => (int) $row->{$metric},
        ];
    }

    private function reportRange(Request $request): array
    {
        $request->validate([
            'range' => ['nullable', 'in:today,yesterday,this_week,this_month,custom'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'curry_item_id' => ['nullable', 'integer', 'exists:curry_items,id'],
            'paid_status' => ['nullable', 'in:fully_paid,partially_paid,unpaid'],
        ]);
        $range = $request->query('range', 'today');
        $fromInput = $request->query('from');
        $toInput = $request->query('to');
        $today = now()->timezone(config('app.timezone'))->startOfDay();

        if ((filled($fromInput) && ! filled($toInput)) || (! filled($fromInput) && filled($toInput))) {
            throw ValidationException::withMessages([
                'date_range' => ['Both from and to are required for custom date ranges.'],
            ]);
        }

        if (filled($fromInput) && filled($toInput)) {
            $from = Carbon::parse($fromInput)->startOfDay();
            $to = Carbon::parse($toInput)->endOfDay();

            if ($from->greaterThan($to)) {
                throw ValidationException::withMessages([
                    'date_range' => ['Date from must not be after date to.'],
                ]);
            }

            return [
                'from' => $from,
                'to' => $to,
            ];
        }

        return match ($range) {
            'yesterday' => ['from' => $today->copy()->subDay(), 'to' => $today->copy()->subSecond()],
            'this_week' => ['from' => $today->copy()->startOfWeek(), 'to' => $today->copy()->endOfWeek()],
            'this_month' => ['from' => $today->copy()->startOfMonth(), 'to' => $today->copy()->endOfMonth()],
            default => ['from' => $today, 'to' => now()->timezone(config('app.timezone'))->endOfDay()],
        };
    }

    private function salesQuery(array $filters, Request $request)
    {
        $query = Sale::query()
            ->where('is_reversed', false)
            ->whereBetween('sale_at', [$filters['from'], $filters['to']])
            ->orderBy('sale_at');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->query('customer_id'));
        }

        if ($request->filled('curry_item_id')) {
            $query->whereHas('items', fn ($q) => $q->where('curry_item_id', (int) $request->query('curry_item_id')));
        }

        return $query;
    }

    private function applyPaidStatusFilter($query, ?string $status)
    {
        return match ($status) {
            'fully_paid' => $query->where('unpaid_kyat', '<=', 0),
            'partially_paid' => $query->where('unpaid_kyat', '>', 0)->where('paid_kyat', '>', 0),
            'unpaid' => $query->where('paid_kyat', 0),
            default => $query,
        };
    }

    private function ledgerSumForFilters($query, Request $request, string $eventType, array $filters): int
    {
        return (int) $query
            ->where('event_type', $eventType)
            ->whereDoesntHave('reversedBy')
            ->whereBetween('occurred_at', [$filters['from'], $filters['to']])
            ->when($request->filled('customer_id'), function ($ledgerQuery) use ($request) {
                $ledgerQuery->where('customer_id', (int) $request->query('customer_id'));
            })
            ->sum(DB::raw($eventType === 'customer_paid' ? 'abs(amount_kyat)' : 'amount_kyat'));
    }
}
