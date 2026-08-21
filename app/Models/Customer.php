<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $appends = [
        'current_balance_kyat',
    ];

    protected $fillable = [
        'name',
        'phone_number',
        'address_or_note',
        'is_archived',
        'opening_balance_kyat',
        'opening_balance_reason',
        'is_active',
    ];

    protected $casts = [
        'is_archived' => 'bool',
        'is_active' => 'bool',
        'opening_balance_kyat' => 'int',
    ];

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    public function currentBalanceKyat(): int
    {
        if (array_key_exists('ledger_balance', $this->attributes)) {
            return (int) $this->attributes['ledger_balance'];
        }

        return (int) $this->ledgerEntries()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('balance_after_kyat');
    }

    public function getCurrentBalanceKyatAttribute(): int
    {
        return $this->currentBalanceKyat();
    }
}
