<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurryItem extends Model
{
    protected $fillable = [
        'curry_category_id',
        'name',
        'current_price_kyat',
        'is_available',
        'display_order',
        'is_archived',
    ];

    protected $casts = [
        'is_available' => 'bool',
        'is_archived' => 'bool',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CurryCategory::class, 'curry_category_id');
    }
}
