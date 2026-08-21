<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HistoryController extends Controller
{
    public function index(Request $request)
    {
        $range = $this->range($request);
        $type = $request->query('type', 'all');
        $customerId = $request->integer('customer_id') ?: null;
        $perPage = min(50, max(5, $request->integer('per_page', 20)));
        $page = max(1, $request->integer('page', 1));
        $rows = collect();

        if (in_array($type, ['all', 'sale'], true)) {
            $sales = Sale::query()
                ->with('customer:id,name')
                ->whereBetween('sale_at', [$range['from'], $range['to']])
                ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
                ->get();

            foreach ($sales as $sale) {
                $rows->push([
                    'key' => 'sale-'.$sale->id,
                    'type' => 'sale',
                    'record_id' => $sale->id,
                    'occurred_at' => $sale->sale_at,
                    'customer' => $sale->customer,
                    'title' => $sale->invoice_number,
                    'amount_kyat' => $sale->total_kyat,
                    'is_reversed' => $sale->is_reversed,
                ]);
            }
        }

        if (in_array($type, ['all', 'customer_paid', 'money_lent'], true)) {
            $entries = CustomerLedgerEntry::query()
                ->with(['customer:id,name', 'reversedBy:id,reverses_entry_id'])
                ->whereIn('event_type', $type === 'all' ? ['customer_paid', 'money_lent'] : [$type])
                ->whereBetween('occurred_at', [$range['from'], $range['to']])
                ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
                ->get();

            foreach ($entries as $entry) {
                $rows->push([
                    'key' => 'ledger-'.$entry->id,
                    'type' => $entry->event_type,
                    'record_id' => $entry->id,
                    'occurred_at' => $entry->occurred_at,
                    'customer' => $entry->customer,
                    'title' => $entry->reason,
                    'amount_kyat' => abs($entry->amount_kyat),
                    'is_reversed' => $entry->reversedBy->isNotEmpty(),
                ]);
            }
        }

        $rows = $rows->sortByDesc(fn ($row) => sprintf('%s-%010d', Carbon::parse($row['occurred_at'])->format('YmdHis.u'), $row['record_id']))->values();
        $total = $rows->count();

        return response()->json([
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'total' => $total,
            'filters' => [
                'range' => $request->query('range', 'today'),
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
                'type' => $type,
                'customer_id' => $customerId,
            ],
        ]);
    }

    public function filterOptions()
    {
        return response()->json([
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'is_archived']),
        ]);
    }

    public function sale(Sale $sale)
    {
        return response()->json($sale->load([
            'customer:id,name,phone_number',
            'user:id,name',
            'items:id,sale_id,curry_item_id,curry_name_snapshot,quantity,unit_price_snapshot_kyat,line_total_kyat',
        ]));
    }

    private function range(Request $request): array
    {
        $range = $request->query('range', 'today');
        $fromInput = $request->query('from');
        $toInput = $request->query('to');
        $today = now()->timezone(config('app.timezone'))->startOfDay();

        if ((filled($fromInput) && ! filled($toInput)) || (! filled($fromInput) && filled($toInput))) {
            throw ValidationException::withMessages(['date_range' => ['Both dates are required.']]);
        }

        if (filled($fromInput) && filled($toInput)) {
            $from = Carbon::parse($fromInput, config('app.timezone'))->startOfDay();
            $to = Carbon::parse($toInput, config('app.timezone'))->endOfDay();
            if ($from->greaterThan($to)) {
                throw ValidationException::withMessages(['date_range' => ['From date must not be after to date.']]);
            }

            return compact('from', 'to');
        }

        return match ($range) {
            'yesterday' => ['from' => $today->copy()->subDay(), 'to' => $today->copy()->subSecond()],
            'this_week' => ['from' => $today->copy()->startOfWeek(), 'to' => $today->copy()->endOfWeek()],
            'this_month' => ['from' => $today->copy()->startOfMonth(), 'to' => $today->copy()->endOfMonth()],
            default => ['from' => $today, 'to' => $today->copy()->endOfDay()],
        };
    }
}
