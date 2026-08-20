<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $fillable = [
        'user_id',
        'customer_id',
        'invoice_number',
        'idempotency_key',
        'sale_at',
        'is_walk_in',
        'subtotal_kyat',
        'discount_kyat',
        'total_kyat',
        'paid_kyat',
        'unpaid_kyat',
        'is_reversed',
        'note',
    ];

    protected $casts = [
        'is_walk_in' => 'bool',
        'is_reversed' => 'bool',
        'sale_at' => 'datetime',
        'subtotal_kyat' => 'int',
        'discount_kyat' => 'int',
        'total_kyat' => 'int',
        'paid_kyat' => 'int',
        'unpaid_kyat' => 'int',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
