<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Services\AuditService;
use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function ledger(Customer $customer, Request $request)
    {
        $perPage = min(50, max(5, $request->integer('per_page', 20)));

        return $this->buildLedgerQuery($customer, $request)
            ->orderBy('occurred_at', 'desc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function show(Customer $customer)
    {
        return response()->json($customer);
    }

    public function index(Request $request)
    {
        $q = $request->query('q');

        $query = Customer::query()
            ->when($request->boolean('include_archived'), fn ($query) => $query->whereRaw('1=1'), fn ($query) => $query->where('is_active', true));

        if (! empty($q)) {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('phone_number', 'like', "%{$q}%");
            });
        }

        return $query->orderBy('name')->get();
    }

    public function store(Request $request, LedgerService $ledgerService, AuditService $auditService)
    {
        return $this->saveWithLedger($request, null, $ledgerService, $auditService);
    }

    public function update(Request $request, Customer $customer, LedgerService $ledgerService, AuditService $auditService)
    {
        return $this->saveWithLedger($request, $customer, $ledgerService, $auditService);
    }

    public function recordPayment(Request $request, Customer $customer, LedgerService $ledgerService, AuditService $auditService)
    {
        $data = $request->validate([
            'amount_kyat' => ['required', 'integer', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        $this->validateMoneyOccurredAt($request, $data['occurred_at'] ?? null);

        $meta = ['note' => $data['note'] ?? null, 'occurred_at' => $data['occurred_at'] ?? null];
        if (! empty($data['idempotency_key'])) {
            $existing = $this->findExistingLedgerEntry($customer, $request, $data['idempotency_key'], 'customer_paid');
            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        $entry = DB::transaction(function () use ($customer, $request, $ledgerService, $auditService, $data, $meta) {
            $entry = $ledgerService->appendEntry($customer, $request->user(), -((int) $data['amount_kyat']), 'customer_paid', [
                'reason' => $data['reason'],
                'meta' => $meta,
                'occurred_at' => $data['occurred_at'] ?? now(),
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);
            if ($entry->wasRecentlyCreated) {
                $auditService->record($request->user(), 'customer_payment_recorded', $entry, [
                    'customer_id' => $customer->id,
                    'amount_kyat' => (int) $data['amount_kyat'],
                ], $data['reason']);
            }

            return $entry;
        });

        return response()->json($entry, $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function recordMoneyLent(Request $request, Customer $customer, LedgerService $ledgerService, AuditService $auditService)
    {
        $data = $request->validate([
            'amount_kyat' => ['required', 'integer', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        $this->validateMoneyOccurredAt($request, $data['occurred_at'] ?? null);

        $meta = ['note' => $data['note'] ?? null, 'occurred_at' => $data['occurred_at'] ?? null];
        if (! empty($data['idempotency_key'])) {
            $existing = $this->findExistingLedgerEntry($customer, $request, $data['idempotency_key'], 'money_lent');
            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        $entry = DB::transaction(function () use ($customer, $request, $ledgerService, $auditService, $data, $meta) {
            $entry = $ledgerService->appendEntry($customer, $request->user(), (int) $data['amount_kyat'], 'money_lent', [
                'reason' => $data['reason'],
                'meta' => $meta,
                'occurred_at' => $data['occurred_at'] ?? now(),
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);
            if ($entry->wasRecentlyCreated) {
                $auditService->record($request->user(), 'customer_money_lent_recorded', $entry, [
                    'customer_id' => $customer->id,
                    'amount_kyat' => (int) $data['amount_kyat'],
                ], $data['reason']);
            }

            return $entry;
        });

        return response()->json($entry, $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function reverseLedgerEntry(
        Request $request,
        Customer $customer,
        CustomerLedgerEntry $ledgerEntry,
        LedgerService $ledgerService,
        AuditService $auditService
    ) {
        if (! $request->user()->hasPermission('correct_reverse_ledger')) {
            throw ValidationException::withMessages([
                'permission' => ['Cannot reverse ledger entry.'],
            ]);
        }

        if ($ledgerEntry->customer_id !== $customer->id) {
            throw new ModelNotFoundException('Customer ledger entry does not belong to customer.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if (! in_array($ledgerEntry->event_type, ['customer_paid', 'money_lent', 'opening_balance_adjustment'], true)) {
            throw ValidationException::withMessages([
                'event_type' => ['This ledger event type cannot be reversed through this endpoint.'],
            ]);
        }

        $entry = DB::transaction(function () use ($ledgerEntry, $customer, $request, $ledgerService, $auditService, $data) {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $lockedEntry = CustomerLedgerEntry::query()
                ->whereKey($ledgerEntry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (CustomerLedgerEntry::query()->where('reverses_entry_id', $lockedEntry->id)->exists()) {
                throw ValidationException::withMessages([
                    'ledger_entry' => ['This ledger entry has already been reversed.'],
                ]);
            }

            $entry = $ledgerService->reverseEntry(
                $lockedEntry,
                $customer,
                $request->user(),
                'ledger_entry_reversed',
                [
                    'ledger_entry_id' => $ledgerEntry->id,
                    'reason' => $data['reason'],
                    'occurred_at' => now(),
                    'meta' => [
                        'original_event_type' => $lockedEntry->event_type,
                        'original_reason' => $lockedEntry->reason,
                    ],
                ]
            );
            $auditService->record($request->user(), 'ledger_entry_reversed', $entry, [
                'customer_id' => $customer->id,
                'reverses_entry_id' => $lockedEntry->id,
            ], $data['reason']);

            return $entry;
        });

        return response()->json($entry, 200);
    }

    public function correctLedgerEntry(
        Request $request,
        Customer $customer,
        CustomerLedgerEntry $ledgerEntry,
        LedgerService $ledgerService,
        AuditService $auditService
    ) {
        if ($ledgerEntry->customer_id !== $customer->id) {
            throw new ModelNotFoundException('Customer ledger entry does not belong to customer.');
        }

        $data = $request->validate([
            'amount_kyat' => ['required', 'integer', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ]);
        $this->validateMoneyOccurredAt($request, $data['occurred_at'] ?? null);

        if (! in_array($ledgerEntry->event_type, ['customer_paid', 'money_lent'], true)) {
            throw ValidationException::withMessages([
                'event_type' => ['Only customer payments and money given to a customer can be corrected here.'],
            ]);
        }

        $result = DB::transaction(function () use ($ledgerEntry, $customer, $request, $ledgerService, $auditService, $data) {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $lockedEntry = CustomerLedgerEntry::query()
                ->whereKey($ledgerEntry->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (CustomerLedgerEntry::query()->where('reverses_entry_id', $lockedEntry->id)->exists()) {
                throw ValidationException::withMessages([
                    'ledger_entry' => ['This ledger entry has already been reversed or corrected.'],
                ]);
            }

            $reversal = $ledgerService->reverseEntry(
                $lockedEntry,
                $customer,
                $request->user(),
                'ledger_entry_reversed',
                [
                    'reason' => $data['reason'],
                    'occurred_at' => now(),
                    'meta' => [
                        'correction' => true,
                        'original_event_type' => $lockedEntry->event_type,
                        'original_reason' => $lockedEntry->reason,
                    ],
                ]
            );

            $signedAmount = $lockedEntry->event_type === 'customer_paid'
                ? -((int) $data['amount_kyat'])
                : (int) $data['amount_kyat'];
            $replacement = $ledgerService->appendEntry(
                $customer,
                $request->user(),
                $signedAmount,
                $lockedEntry->event_type,
                [
                    'reason' => $data['reason'],
                    'occurred_at' => $data['occurred_at'] ?? now(),
                    'meta' => [
                        'note' => $data['note'] ?? null,
                        'correction_of_entry_id' => $lockedEntry->id,
                        'correction_reversal_entry_id' => $reversal->id,
                    ],
                ]
            );

            $auditService->record($request->user(), 'ledger_entry_corrected', $replacement, [
                'customer_id' => $customer->id,
                'original_entry_id' => $lockedEntry->id,
                'reversal_entry_id' => $reversal->id,
                'before' => [
                    'event_type' => $lockedEntry->event_type,
                    'amount_kyat' => $lockedEntry->amount_kyat,
                    'occurred_at' => $lockedEntry->occurred_at,
                    'note' => $lockedEntry->meta['note'] ?? null,
                ],
                'after' => [
                    'event_type' => $replacement->event_type,
                    'amount_kyat' => $replacement->amount_kyat,
                    'occurred_at' => $replacement->occurred_at,
                    'note' => $replacement->meta['note'] ?? null,
                ],
            ], $data['reason']);

            return compact('reversal', 'replacement');
        });

        return response()->json($result, 200);
    }

    private function saveWithLedger(Request $request, ?Customer $customer, LedgerService $ledgerService, AuditService $auditService)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone_number' => ['nullable', 'string', 'max:80'],
            'address_or_note' => ['nullable', 'string'],
            'is_archived' => ['nullable', 'boolean'],
            'opening_balance_kyat' => ['nullable', 'integer'],
            'opening_balance_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $openingBalance = (int) ($data['opening_balance_kyat'] ?? 0);
        if (! $customer && $openingBalance !== 0 && empty($data['opening_balance_reason'])) {
            throw ValidationException::withMessages([
                'opening_balance_reason' => ['Reason is required when opening balance is not zero'],
            ]);
        }

        if (! $customer) {
            return DB::transaction(function () use ($data, $openingBalance, $request, $ledgerService, $auditService) {
                $customer = Customer::create([
                    'name' => $data['name'],
                    'phone_number' => $data['phone_number'] ?? null,
                    'address_or_note' => $data['address_or_note'] ?? null,
                    'is_archived' => $data['is_archived'] ?? false,
                    'is_active' => ! ($data['is_archived'] ?? false),
                    'opening_balance_kyat' => $openingBalance,
                    'opening_balance_reason' => $data['opening_balance_reason'] ?? null,
                ]);

                if ($openingBalance !== 0) {
                    $ledgerService->appendEntry($customer, $request->user(), $openingBalance, 'opening_balance', [
                        'reason' => $data['opening_balance_reason'],
                        'meta' => ['initial_opening_balance' => true],
                    ]);
                }
                $auditService->record($request->user(), 'customer_created', $customer, $customer->toArray(), $data['opening_balance_reason'] ?? null);

                return response()->json($customer, 201);
            });
        }

        return DB::transaction(function () use ($customer, $data, $request, $ledgerService, $auditService) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $before = $customer->only(['name', 'phone_number', 'address_or_note', 'is_archived', 'opening_balance_kyat']);
            $oldBalance = (int) $customer->opening_balance_kyat;
            $newBalance = array_key_exists('opening_balance_kyat', $data) ? (int) $data['opening_balance_kyat'] : $oldBalance;

            if ($newBalance !== $oldBalance && empty($data['opening_balance_reason'])) {
                throw ValidationException::withMessages([
                    'opening_balance_reason' => ['Reason is required when opening balance changes'],
                ]);
            }

            if ($newBalance !== $oldBalance) {
                $ledgerService->appendEntry($customer, $request->user(), $newBalance - $oldBalance, 'opening_balance_adjustment', [
                    'reason' => $data['opening_balance_reason'] ?? null,
                ]);
            }

            $customer->fill([
                'name' => $data['name'],
                'phone_number' => $data['phone_number'] ?? null,
                'address_or_note' => $data['address_or_note'] ?? null,
                'is_archived' => $data['is_archived'] ?? $customer->is_archived,
                'is_active' => isset($data['is_archived']) ? ! $data['is_archived'] : $customer->is_active,
                'opening_balance_kyat' => $newBalance,
                'opening_balance_reason' => $data['opening_balance_reason'] ?? $customer->opening_balance_reason,
            ]);
            $customer->save();
            $auditService->record($request->user(), 'customer_updated', $customer, [
                'before' => $before,
                'after' => $customer->only(['name', 'phone_number', 'address_or_note', 'is_archived', 'opening_balance_kyat']),
            ], $data['opening_balance_reason'] ?? null);

            return response()->json($customer);
        });
    }

    private function findExistingLedgerEntry(Customer $customer, $request, string $idempotencyKey, string $eventType): ?CustomerLedgerEntry
    {
        $existing = CustomerLedgerEntry::query()
            ->where('actor_user_id', $request->user()->id)
            ->where('idempotency_key', $idempotencyKey)
            ->latest('id')
            ->first();

        if ($existing && ($existing->customer_id !== $customer->id || $existing->event_type !== $eventType)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['This idempotency key was already used for a different customer money action.'],
            ]);
        }

        return $existing;
    }

    private function validateMoneyOccurredAt(Request $request, ?string $occurredAt): void
    {
        if (! filled($occurredAt)) {
            return;
        }

        $eventTime = Carbon::parse($occurredAt);
        if ($eventTime->isFuture()) {
            throw ValidationException::withMessages([
                'occurred_at' => ['Financial event date and time cannot be in the future.'],
            ]);
        }

        if (! $request->user()?->hasPermission('backdate_sale') && ! $eventTime->isToday()) {
            throw ValidationException::withMessages([
                'occurred_at' => ['Backdating a financial event requires backdate permission.'],
            ]);
        }
    }

    private function parseDateRange(Request $request): array
    {
        $fromInput = $request->query('from');
        $toInput = $request->query('to');

        if ((filled($fromInput) && ! filled($toInput)) || (! filled($fromInput) && filled($toInput))) {
            throw ValidationException::withMessages([
                'date_range' => ['Both from and to are required for custom statement range filtering.'],
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

            return ['from' => $from, 'to' => $to, 'is_custom' => true];
        }

        $today = now()->timezone(config('app.timezone'))->startOfDay();

        return [
            'from' => Carbon::create(1000, 1, 1, 0, 0, 0, config('app.timezone')),
            'to' => $today->copy()->endOfDay(),
            'is_custom' => false,
        ];
    }

    private function buildLedgerQuery(Customer $customer, Request $request)
    {
        $range = $this->parseDateRange($request);
        $eventType = $request->query('event_type');

        $query = $customer->ledgerEntries()
            ->with([
                'actor:id,name',
                'reversedBy:id,reverses_entry_id',
                'reversesEntry:id,event_type',
            ])
            ->whereBetween('occurred_at', [$range['from'], $range['to']]);

        if (! empty($eventType)) {
            $query->where('event_type', $eventType);
        }

        return $query;
    }
}
