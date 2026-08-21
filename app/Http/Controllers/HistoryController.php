<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HistoryController extends Controller
{
    public function index(Request $request)
    {
        $range = $this->range($request);
        $type = $request->query('type', 'all');
        $customerId = $request->integer('customer_id') ?: null;
        $perPage = min(50, max(5, $request->integer('per_page', 20)));
        $sales = DB::table('sales')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->whereBetween('sales.sale_at', [$range['from'], $range['to']])
            ->when($customerId, fn ($query) => $query->where('sales.customer_id', $customerId))
            ->when($type !== 'all', fn ($query) => $query->whereRaw($type === 'sale' ? '1 = 1' : '1 = 0'))
            ->selectRaw("'sale' as type")
            ->addSelect([
                'sales.id as record_id',
                'sales.sale_at as occurred_at',
                'sales.customer_id',
                'customers.name as customer_name',
                'sales.invoice_number as title',
                'sales.total_kyat as amount_kyat',
                'sales.is_reversed',
            ]);

        $ledger = DB::table('customer_ledger_entries')
            ->leftJoin('customers', 'customers.id', '=', 'customer_ledger_entries.customer_id')
            ->whereBetween('customer_ledger_entries.occurred_at', [$range['from'], $range['to']])
            ->whereIn('customer_ledger_entries.event_type', $type === 'all' ? ['customer_paid', 'money_lent'] : [$type])
            ->when($customerId, fn ($query) => $query->where('customer_ledger_entries.customer_id', $customerId))
            ->select([
                'customer_ledger_entries.event_type as type',
                'customer_ledger_entries.id as record_id',
                'customer_ledger_entries.occurred_at',
                'customer_ledger_entries.customer_id',
                'customers.name as customer_name',
                'customer_ledger_entries.reason as title',
            ])
            ->selectRaw('ABS(customer_ledger_entries.amount_kyat) as amount_kyat')
            ->selectRaw('CASE WHEN EXISTS (SELECT 1 FROM customer_ledger_entries reversals WHERE reversals.reverses_entry_id = customer_ledger_entries.id) THEN 1 ELSE 0 END as is_reversed');

        $rows = DB::query()
            ->fromSub($sales->unionAll($ledger), 'history')
            ->orderByDesc('occurred_at')
            ->orderByDesc('record_id')
            ->paginate($perPage);

        $rows->through(fn ($row) => [
            'type' => $row->type,
            'record_id' => $row->record_id,
            'occurred_at' => $row->occurred_at,
            'customer' => $row->customer_id ? ['id' => $row->customer_id, 'name' => $row->customer_name] : null,
            'title' => $row->title,
            'amount_kyat' => (int) $row->amount_kyat,
            'is_reversed' => (bool) $row->is_reversed,
        ]);

        return response()->json([
            ...$rows->toArray(),
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
