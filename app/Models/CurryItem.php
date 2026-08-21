<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurryItem extends Model
{
    protected $fillable = [
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
}
