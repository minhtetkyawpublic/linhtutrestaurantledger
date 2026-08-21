<?php

namespace App\Http\Controllers;

use App\Models\CurryItem;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function createOptions()
    {
        return response()->json([
            'customers' => Customer::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'phone_number']),
            'curries' => CurryItem::query()
                ->where('is_available', true)
                ->where('is_archived', false)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function editOptions()
    {
        return response()->json([
            'customers' => Customer::query()
                ->orderBy('name')
                ->get(['id', 'name', 'phone_number', 'is_active', 'is_archived']),
            'curries' => CurryItem::query()
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function index(Request $request)
    {
        return Sale::query()
            ->with('customer:id,name', 'items:id,sale_id,curry_item_id,curry_name_snapshot,quantity,unit_price_snapshot_kyat,line_total_kyat')
            ->orderByDesc('sale_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    public function store(Request $request, LedgerService $ledgerService, AuditService $auditService)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'sale_at' => ['required', 'date'],
            'is_walk_in' => ['required', 'boolean'],
            'note' => ['nullable', 'string'],
            'discount_kyat' => ['required', 'integer', 'min:0'],
            'paid_kyat' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.curry_item_id' => ['required', 'integer', 'exists:curry_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $saleAt = Carbon::parse($data['sale_at']);
        $today = Carbon::today(config('app.timezone'));

        if ($saleAt->greaterThan(Carbon::now())) {
            throw ValidationException::withMessages([
                'sale_at' => ['Sale date and time cannot be in the future.'],
            ]);
        }

        if (! $request->user()?->hasPermission('backdate_sale') && ! $saleAt->isSameDay($today)) {
            throw ValidationException::withMessages([
                'sale_at' => ['Backdating a sale requires backdate permission.'],
            ]);
        }

        if ($data['is_walk_in'] && ! empty($data['customer_id'])) {
            throw ValidationException::withMessages([
                'customer_id' => ['Walk-in sales must not use a customer_id.'],
            ]);
        }

        if (! $data['is_walk_in'] && empty($data['customer_id'])) {
            throw ValidationException::withMessages([
                'customer_id' => ['Named customer sales require a customer.'],
            ]);
        }

        if (! empty($data['idempotency_key'])) {
            $existing = Sale::query()
                ->where('user_id', $request->user()->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->with('items', 'customer')
                ->first();

            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        $preparedItems = $this->prepareSaleItems($data['items']);

        $subtotal = array_sum(array_column($preparedItems, 'line_total_kyat'));
        $total = max(0, $subtotal - (int) $data['discount_kyat']);
        $unpaid = $total - (int) $data['paid_kyat'];

        if ($data['is_walk_in'] && $unpaid !== 0) {
            throw ValidationException::withMessages([
                'paid_kyat' => ['Walk-in customer sales must be fully paid and cannot be overpaid.'],
            ]);
        }

        $customer = null;
        if (! $data['is_walk_in']) {
            $customer = Customer::query()->findOrFail($data['customer_id']);
        }

        $payload = [
            'user_id' => $request->user()->id,
            'customer_id' => $customer?->id,
            'invoice_number' => $this->generateInvoiceNumber(),
            'sale_at' => $data['sale_at'],
            'is_walk_in' => $data['is_walk_in'],
            'subtotal_kyat' => $subtotal,
            'discount_kyat' => (int) $data['discount_kyat'],
            'total_kyat' => $total,
            'paid_kyat' => (int) $data['paid_kyat'],
            'unpaid_kyat' => $unpaid,
            'note' => $data['note'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ];

        $duplicate = false;
        $sale = DB::transaction(function () use ($payload, $preparedItems, $customer, $ledgerService, $auditService, $request, $data, &$duplicate) {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if (! empty($payload['idempotency_key'])) {
                $existing = Sale::query()
                    ->where('user_id', $request->user()->id)
                    ->where('idempotency_key', $payload['idempotency_key'])
                    ->with('items', 'customer')
                    ->first();
                if ($existing) {
                    $duplicate = true;

                    return $existing;
                }
            }

            $sale = Sale::query()->create($payload);

            foreach ($preparedItems as $lineItem) {
                $sale->items()->save(new SaleItem([
                    'curry_item_id' => $lineItem['curry_item_id'],
                    'curry_name_snapshot' => $lineItem['curry_name_snapshot'],
                    'quantity' => $lineItem['quantity'],
                    'unit_price_snapshot_kyat' => $lineItem['unit_price_snapshot_kyat'],
                    'line_total_kyat' => $lineItem['line_total_kyat'],
                ]));
            }

            if (! $payload['is_walk_in'] && $customer) {
                $ledgerService->appendEntry($customer, $request->user(), (int) $sale->unpaid_kyat, 'sale_created', [
                    'sale_id' => $sale->id,
                    'occurred_at' => $data['sale_at'],
                    'meta' => [
                        'note' => $data['note'] ?? null,
                        'invoice_number' => $sale->invoice_number,
                    ],
                ]);
            }

            $auditService->record($request->user(), 'sale_created', $sale, [
                'customer_id' => $sale->customer_id,
                'total_kyat' => $sale->total_kyat,
                'paid_kyat' => $sale->paid_kyat,
                'unpaid_kyat' => $sale->unpaid_kyat,
            ]);

            return $sale;
        });

        return response()->json($sale->fresh('items')->load('customer:id,name'), $duplicate ? 200 : 201);
    }

    public function update(Request $request, Sale $sale, LedgerService $ledgerService, AuditService $auditService)
    {
        if ($sale->is_reversed) {
            throw ValidationException::withMessages([
                'sale' => ['Cannot edit a reversed sale.'],
            ]);
        }

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'sale_at' => ['required', 'date'],
            'is_walk_in' => ['required', 'boolean'],
            'note' => ['nullable', 'string'],
            'discount_kyat' => ['required', 'integer', 'min:0'],
            'paid_kyat' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.curry_item_id' => ['required', 'integer', 'exists:curry_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $saleAt = Carbon::parse($data['sale_at']);

        if ($saleAt->greaterThan(Carbon::now())) {
            throw ValidationException::withMessages([
                'sale_at' => ['Sale date and time cannot be in the future.'],
            ]);
        }

        if (! $request->user()?->hasPermission('backdate_sale') && ! $saleAt->isToday()) {
            throw ValidationException::withMessages([
                'sale_at' => ['Backdating a sale requires backdate permission.'],
            ]);
        }

        if ($data['is_walk_in'] && ! empty($data['customer_id'])) {
            throw ValidationException::withMessages([
                'customer_id' => ['Walk-in sales must not use a customer_id.'],
            ]);
        }

        if (! $data['is_walk_in'] && empty($data['customer_id'])) {
            throw ValidationException::withMessages([
                'customer_id' => ['Named customer sales require a customer.'],
            ]);
        }

        $preparedItems = $this->prepareSaleItems($data['items']);
        $subtotal = array_sum(array_column($preparedItems, 'line_total_kyat'));
        $total = max(0, $subtotal - (int) $data['discount_kyat']);
        $unpaid = $total - (int) $data['paid_kyat'];

        if ($data['is_walk_in'] && $unpaid !== 0) {
            throw ValidationException::withMessages([
                'paid_kyat' => ['Walk-in customer sales must be fully paid and cannot be overpaid.'],
            ]);
        }

        $customer = null;
        if (! $data['is_walk_in']) {
            $customer = Customer::query()->findOrFail($data['customer_id']);
        }

        $sale = DB::transaction(function () use ($sale, $request, $preparedItems, $unpaid, $subtotal, $data, $ledgerService, $auditService, $customer, $total) {
            $lockedSale = Sale::query()
                ->with('items')
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSale->is_reversed) {
                throw ValidationException::withMessages([
                    'sale' => ['Cannot edit a reversed sale.'],
                ]);
            }

            $before = $lockedSale->only(['customer_id', 'sale_at', 'discount_kyat', 'paid_kyat', 'unpaid_kyat', 'note']);
            $before['items'] = $lockedSale->items
                ->map->only(['curry_item_id', 'curry_name_snapshot', 'quantity', 'unit_price_snapshot_kyat', 'line_total_kyat'])
                ->values()
                ->all();

            if (! $lockedSale->is_walk_in && $lockedSale->customer_id) {
                $oldCustomer = Customer::query()->findOrFail($lockedSale->customer_id);
                if ($previous = $ledgerService->latestAffectingEntryForSale($lockedSale->id)) {
                    $ledgerService->reverseEntry(
                        $previous,
                        $oldCustomer,
                        $request->user(),
                        'sale_edit_reversed',
                        [
                            'sale_id' => $lockedSale->id,
                            'occurred_at' => $data['sale_at'],
                            'reason' => 'Sale corrected',
                            'meta' => ['reason' => $data['reason']],
                        ]
                    );
                }
            }

            $lockedSale->fill([
                'customer_id' => $customer?->id,
                'sale_at' => $data['sale_at'],
                'is_walk_in' => $data['is_walk_in'],
                'subtotal_kyat' => $subtotal,
                'discount_kyat' => (int) $data['discount_kyat'],
                'total_kyat' => $total,
                'paid_kyat' => (int) $data['paid_kyat'],
                'unpaid_kyat' => $unpaid,
                'note' => $data['note'] ?? null,
            ])->save();

            $lockedSale->items()->delete();

            foreach ($preparedItems as $lineItem) {
                $lockedSale->items()->create([
                    'curry_item_id' => $lineItem['curry_item_id'],
                    'curry_name_snapshot' => $lineItem['curry_name_snapshot'],
                    'quantity' => $lineItem['quantity'],
                    'unit_price_snapshot_kyat' => $lineItem['unit_price_snapshot_kyat'],
                    'line_total_kyat' => $lineItem['line_total_kyat'],
                ]);
            }

            if (! $data['is_walk_in'] && $customer) {
                $ledgerService->appendEntry($customer, $request->user(), (int) $unpaid, 'sale_updated', [
                    'sale_id' => $lockedSale->id,
                    'occurred_at' => $data['sale_at'],
                    'reason' => $data['reason'],
                    'meta' => [
                        'note' => $data['note'] ?? null,
                        'invoice_number' => $lockedSale->invoice_number,
                    ],
                ]);
            }

            $auditService->record($request->user(), 'sale_updated', $lockedSale, [
                'before' => $before,
                'after' => array_merge(
                    $lockedSale->only(['customer_id', 'sale_at', 'discount_kyat', 'paid_kyat', 'unpaid_kyat', 'note']),
                    ['items' => $lockedSale->items()->get(['curry_item_id', 'curry_name_snapshot', 'quantity', 'unit_price_snapshot_kyat', 'line_total_kyat'])->toArray()]
                ),
            ], $data['reason']);

            return $lockedSale;
        });

        return response()->json($sale->fresh('items')->load('customer:id,name'));
    }

    public function reverse(Request $request, Sale $sale, LedgerService $ledgerService, AuditService $auditService)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if (! $request->user()?->hasPermission('delete_reverse_sale')) {
            throw ValidationException::withMessages([
                'permission' => ['Cannot reverse sale.'],
            ]);
        }

        DB::transaction(function () use ($sale, $request, $ledgerService, $auditService, $data) {
            $lockedSale = Sale::query()
                ->with('customer')
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSale->is_reversed) {
                throw ValidationException::withMessages([
                    'sale' => ['Sale is already reversed.'],
                ]);
            }

            if (! $lockedSale->is_walk_in && $lockedSale->customer_id) {
                $latest = $ledgerService->latestAffectingEntryForSale($lockedSale->id);

                if ($latest) {
                    $ledgerService->reverseEntry(
                        $latest,
                        $lockedSale->customer,
                        $request->user(),
                        'sale_reversed',
                        [
                            'sale_id' => $lockedSale->id,
                            'occurred_at' => now(),
                            'reason' => $data['reason'],
                            'meta' => ['invoice_number' => $lockedSale->invoice_number],
                        ]
                    );
                }
            }

            $lockedSale->is_reversed = true;
            $lockedSale->note = trim(($lockedSale->note ? $lockedSale->note.' ' : '').'[Reversed: '.$data['reason'].']');
            $lockedSale->save();
            $auditService->record($request->user(), 'sale_reversed', $lockedSale, [], $data['reason']);
        });

        return response()->json($sale->fresh());
    }

    private function prepareSaleItems(array $items): array
    {
        $prepared = [];

        foreach ($items as $rawItem) {
            $curry = CurryItem::query()->findOrFail((int) $rawItem['curry_item_id']);

            if (! $curry->is_available || $curry->is_archived) {
                throw ValidationException::withMessages([
                    'curry_item_id' => ["Curry item {$curry->id} is not available for sale."],
                ]);
            }

            $quantity = (int) $rawItem['quantity'];
            $unitPrice = (int) $curry->current_price_kyat;
            $prepared[] = [
                'curry_item_id' => $curry->id,
                'curry_name_snapshot' => $curry->name,
                'quantity' => $quantity,
                'unit_price_snapshot_kyat' => $unitPrice,
                'line_total_kyat' => $quantity * $unitPrice,
            ];
        }

        return $prepared;
    }

    private function generateInvoiceNumber(): string
    {
        return now()->format('YmdHis').random_int(100, 999);
    }
}
