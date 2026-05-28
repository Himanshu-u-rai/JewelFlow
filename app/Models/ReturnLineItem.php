<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReturnLineItem extends Model
{
    use BelongsToShop;
    use ImmutableLedger;

    public const CONDITION_GOOD          = 'good_condition';
    public const CONDITION_MINOR_WEAR    = 'minor_wear';
    public const CONDITION_DAMAGED       = 'damaged';
    public const CONDITION_NON_SELLABLE  = 'non_sellable';

    // Line refund snapshot is locked. Disposition lives in a separate table.
    // We allow condition + reason edits only while the parent return_order is
    // still in draft — service enforces that.
    protected $allowedUpdateColumns = [
        'condition', 'reason',
    ];

    protected $fillable = [
        'shop_id', 'return_order_id', 'invoice_item_id', 'item_id',
        'refund_subtotal', 'refund_gst', 'refund_discount', 'refund_round_off',
        'refund_loyalty_pts', 'refund_total',
        'condition', 'reason', 'policy_breakdown', 'is_overridden',
        'hsn_code',
    ];

    protected $casts = [
        'refund_subtotal'    => 'decimal:2',
        'refund_gst'         => 'decimal:2',
        'refund_discount'    => 'decimal:2',
        'refund_round_off'   => 'decimal:4',
        'refund_loyalty_pts' => 'integer',
        'refund_total'       => 'decimal:2',
        'policy_breakdown'   => 'array',
        'is_overridden'      => 'boolean',
    ];

    public function returnOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function dispositions(): HasMany
    {
        return $this->hasMany(ReturnedItemDisposition::class);
    }

    public function latestDisposition(): HasOne
    {
        return $this->hasOne(ReturnedItemDisposition::class)->latestOfMany();
    }
}
