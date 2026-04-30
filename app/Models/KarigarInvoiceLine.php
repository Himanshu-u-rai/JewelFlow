<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class KarigarInvoiceLine extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'karigar_invoice_id',
        'linked_receipt_item_id',
        'description',
        'hsn_code',
        'pieces',
        'gross_weight',
        'stone_weight',
        'net_weight',
        'purity',
        'rate_per_gram',
        'metal_amount',
        'making_charge',
        'wastage_charge',
        'extra_amount',
        'line_total',
        'note',
    ];

    protected $casts = [
        'pieces' => 'integer',
        'gross_weight' => 'decimal:6',
        'stone_weight' => 'decimal:6',
        'net_weight' => 'decimal:6',
        'purity' => 'decimal:2',
        'rate_per_gram' => 'decimal:2',
        'metal_amount' => 'decimal:2',
        'making_charge' => 'decimal:2',
        'wastage_charge' => 'decimal:2',
        'extra_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(KarigarInvoice::class, 'karigar_invoice_id');
    }

    public function linkedReceiptItem()
    {
        return $this->belongsTo(JobOrderReceiptItem::class, 'linked_receipt_item_id');
    }
}
