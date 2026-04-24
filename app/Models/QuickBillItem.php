<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickBillItem extends Model
{
    use BelongsToShop;

    protected $guarded = ['*'];

    protected $casts = [
        'sort_order' => 'integer',
        'pcs' => 'integer',
        'gross_weight' => 'decimal:3',
        'stone_weight' => 'decimal:3',
        'net_weight' => 'decimal:3',
        'rate' => 'decimal:2',
        'making_charge' => 'decimal:2',
        'stone_charge' => 'decimal:2',
        'wastage_percent' => 'decimal:2',
        'line_discount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function quickBill(): BelongsTo
    {
        return $this->belongsTo(QuickBill::class);
    }
}
