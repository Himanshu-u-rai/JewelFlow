<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockPurchaseItem extends Model
{
    protected $fillable = [
        'stock_purchase_id',
        'shop_id',
        'item_id',
        'line_type',
        'barcode',
        'design',
        'category',
        'sub_category',
        'metal_type',
        'purity',
        'gross_weight',
        'stone_weight',
        'net_metal_weight',
        'huid',
        'hallmark_date',
        'hsn_code',
        'making_charges',
        'stone_charges',
        'hallmark_charges',
        'rhodium_charges',
        'other_charges',
        'purchase_rate_per_gram',
        'purchase_line_amount',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'hallmark_date'        => 'date',
        'purity'               => 'decimal:3',
        'gross_weight'         => 'decimal:3',
        'stone_weight'         => 'decimal:3',
        'net_metal_weight'     => 'decimal:6',
        'making_charges'       => 'decimal:2',
        'stone_charges'        => 'decimal:2',
        'hallmark_charges'     => 'decimal:2',
        'rhodium_charges'      => 'decimal:2',
        'other_charges'        => 'decimal:2',
        'purchase_rate_per_gram' => 'decimal:4',
        'purchase_line_amount' => 'decimal:2',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(StockPurchase::class, 'stock_purchase_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
