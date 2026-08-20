<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'curry_item_id',
        'curry_name_snapshot',
        'quantity',
        'unit_price_snapshot_kyat',
        'line_total_kyat',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function curryItem(): BelongsTo
    {
        return $this->belongsTo(CurryItem::class, 'curry_item_id');
    }
}
