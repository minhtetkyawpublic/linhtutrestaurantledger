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
        return response()->json([
            'customers' => Customer::query()
                ->orderBy('name')
                ->get(['id', 'name', 'is_active', 'is_archived']),
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
        $sales = $salesQuery->get();

        $totalSalesValue = (int) $sales->sum('total_kyat');
        $totalDiscounts = (int) $sales->sum('discount_kyat');
        $totalPaid = (int) $sales->sum('paid_kyat');
        $totalNewDebt = (int) $sales->where('unpaid_kyat', '>', 0)->sum('unpaid_kyat');

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
            'total_sales' => $totalSalesValue,
            'total_discounts' => $totalDiscounts,
            'total_paid_at_sale' => $totalPaid,
            'total_new_sale_debt' => $totalNewDebt,
            'customer_payments_received' => $customerPayments,
            'money_lent_or_returned' => $moneyLent,
            'sales_count' => $sales->count(),
            'reversed_sales_count' => $cancelledCount,
            'reversed_ledger_entries_count' => $reversedLedgerCount,
            'paid_status' => $request->query('paid_status'),
            'sales' => $sales->values(),
        ]);
    }

    public function customerBalances()
    {
        $customers = Customer::query()->get();
        $rows = [];

        $owesShop = 0;
        $shopOwes = 0;

        foreach ($customers as $customer) {
            $balance = (int) $customer->currentBalanceKyat();

            $rows[] = [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'balance' => $balance,
            ];

            if ($balance > 0) {
                $owesShop += $balance;
            } elseif ($balance < 0) {
                $shopOwes += abs($balance);
            }
        }

        return response()->json([
            'total_outstanding' => $owesShop,
            'total_shop_owes' => $shopOwes,
            'customers_who_owe_shop' => array_values(array_filter($rows, fn ($row) => $row['balance'] > 0)),
            'customers_whom_shop_owes' => array_values(array_filter($rows, fn ($row) => $row['balance'] < 0)),
        ]);
    }

    public function topCurries(Request $request)
    {
        $filters = $this->reportRange($request);
        $salesQuery = $this->salesQuery($filters, $request);
        $sales = $this->applyPaidStatusFilter($salesQuery, $request->query('paid_status'))
            ->with('items')
            ->get();

        $byQuantity = [];
        $byValue = [];

        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                if ($item->curry_item_id === null) {
                    continue;
                }

                $itemId = (string) $item->curry_item_id;
                $byQuantity[$itemId] = ($byQuantity[$itemId] ?? 0) + (int) $item->quantity;
                $byValue[$itemId] = ($byValue[$itemId] ?? 0) + (int) $item->line_total_kyat;
            }
        }

        arsort($byQuantity);
        arsort($byValue);

        $itemIds = array_unique(array_merge(array_keys($byQuantity), array_keys($byValue)));
        $itemNames = CurryItem::query()
            ->whereIn('id', $itemIds)
            ->pluck('name', 'id');

        return response()->json([
            'most_sold_curry_by_quantity' => $this->resolveCurryStats(
                array_key_first($byQuantity),
                count($byQuantity) > 0 ? (int) current($byQuantity) : null,
                $itemNames
            ),
            'most_sold_curry_by_value' => $this->resolveCurryStats(
                array_key_first($byValue),
                count($byValue) > 0 ? (int) current($byValue) : null,
                $itemNames
            ),
            'top10_by_quantity' => $this->curryRanking($byQuantity, $itemNames, 'quantity', 10),
            'top10_by_value' => $this->curryRanking($byValue, $itemNames, 'value', 10),
        ]);
    }

    private function curryRanking(array $scores, $itemNames, string $metric, int $limit = 10): array
    {
        $rows = [];
        $i = 0;

        foreach ($scores as $itemId => $value) {
            if ($i >= $limit) {
                break;
            }

            $rows[] = [
                'curry_item_id' => (int) $itemId,
                'name' => $itemNames[(int) $itemId] ?? ('Curry #'.$itemId),
                'metric' => $metric,
                'value' => (int) $value,
            ];
            $i++;
        }

        return $rows;
    }

    private function resolveCurryStats(?string $itemId, ?int $value, $itemNames): ?array
    {
        if ($itemId === null || $value === null) {
            return null;
        }

        return [
            'curry_item_id' => (int) $itemId,
            'name' => $itemNames[(int) $itemId] ?? ('Curry #'.$itemId),
            'value' => (int) $value,
        ];
    }

    private function reportRange(Request $request): array
    {
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
