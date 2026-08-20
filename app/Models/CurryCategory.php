<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurryCategory extends Model
{
    protected $fillable = [
        'name',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'bool',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CurryItem::class);
    }
}
