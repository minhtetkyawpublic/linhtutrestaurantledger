<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    public function currentBalanceForCustomer(int $customerId): int
    {
        return (int) CustomerLedgerEntry::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('balance_after_kyat');
    }

    public function appendEntry(Customer $customer, User $actor, int $amountKyat, string $eventType, array $payload = []): CustomerLedgerEntry
    {
        return DB::transaction(function () use ($customer, $actor, $amountKyat, $eventType, $payload) {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $idempotencyKey = filled($payload['idempotency_key'] ?? null)
                ? (string) $payload['idempotency_key']
                : null;

            if ($idempotencyKey) {
                $meta['idempotency_key'] = $idempotencyKey;
                $existing = CustomerLedgerEntry::query()
                    ->where('actor_user_id', $actor->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $entry = CustomerLedgerEntry::create([
                'customer_id' => $customer->id,
                'actor_user_id' => $actor->id,
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
                'amount_kyat' => $amountKyat,
                'balance_after_kyat' => 0,
                'reason' => $payload['reason'] ?? null,
                'meta' => $meta,
                'reverses_entry_id' => $payload['reverses_entry_id'] ?? null,
                'occurred_at' => $payload['occurred_at'] ?? now(),
                'sale_id' => $payload['sale_id'] ?? null,
            ]);

            $runningBalance = 0;
            CustomerLedgerEntry::query()
                ->where('customer_id', $customer->id)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get()
                ->each(function (CustomerLedgerEntry $ledgerEntry) use (&$runningBalance) {
                    $runningBalance += (int) $ledgerEntry->amount_kyat;
                    if ((int) $ledgerEntry->balance_after_kyat !== $runningBalance) {
                        $ledgerEntry->updateQuietly(['balance_after_kyat' => $runningBalance]);
                    }
                });

            $entry->refresh();

            return $entry;
        });
    }

    public function reverseEntry(
        CustomerLedgerEntry $entry,
        Customer $customer,
        User $actor,
        string $eventType,
        array $payload = []
    ): CustomerLedgerEntry {
        return $this->appendEntry(
            $customer,
            $actor,
            -((int) $entry->amount_kyat),
            $eventType,
            array_merge($payload, [
                'reverses_entry_id' => $entry->id,
            ])
        );
    }

    public function latestAffectingEntryForSale(int $saleId): ?CustomerLedgerEntry
    {
        return CustomerLedgerEntry::query()
            ->where('sale_id', $saleId)
            ->where('event_type', '!=', 'opening_balance')
            ->orderByDesc('id')
            ->first();
    }
}
