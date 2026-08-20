<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerLedgerEntry extends Model
{
    protected $fillable = [
        'customer_id',
        'actor_user_id',
        'sale_id',
        'reverses_entry_id',
        'event_type',
        'idempotency_key',
        'amount_kyat',
        'balance_after_kyat',
        'reason',
        'meta',
        'occurred_at',
    ];

    protected $casts = [
        'amount_kyat' => 'int',
        'balance_after_kyat' => 'int',
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function reversedBy(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class, 'reverses_entry_id');
    }

    public function reversesEntry(): BelongsTo
    {
        return $this->belongsTo(CustomerLedgerEntry::class, 'reverses_entry_id');
    }
}
