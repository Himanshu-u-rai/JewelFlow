<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class JobOrderReceiptItem extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'job_order_receipt_id',
        'item_id',
        'description',
        'hsn_code',
        'pieces',
        'gross_weight',
        'stone_weight',
        'net_weight',
        'purity',
        'fine_weight',
    ];

    protected $casts = [
        'pieces' => 'integer',
        'gross_weight' => 'decimal:6',
        'stone_weight' => 'decimal:6',
        'net_weight' => 'decimal:6',
        'purity' => 'decimal:2',
        'fine_weight' => 'decimal:6',
    ];

    public function receipt()
    {
        return $this->belongsTo(JobOrderReceipt::class, 'job_order_receipt_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
