<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNote extends Model
{
    use BelongsToShop;
    use ImmutableLedger;

    public const STATUS_ISSUED = 'issued';

    // Credit notes are immutable once issued. The only field that could change
    // is rare admin metadata like `reason` (text correction). Kept tight on
    // purpose — see architecture §7.
    protected $allowedUpdateColumns = [
        'reason',
    ];

    protected $fillable = [
        'shop_id', 'return_order_id', 'invoice_id', 'customer_id',
        'credit_note_sequence', 'credit_note_number',
        'subtotal', 'gst', 'gst_rate', 'wastage_charge', 'discount', 'round_off', 'total',
        'status', 'issued_at', 'issued_by_user_id', 'reason',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'exchange_order_id',
        'place_of_supply_state_code', 'buyer_gstin',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:2',
        'gst'             => 'decimal:2',
        'gst_rate'        => 'decimal:2',
        'wastage_charge'  => 'decimal:2',
        'discount'        => 'decimal:2',
        'round_off'       => 'decimal:4',
        'total'           => 'decimal:2',
        'issued_at'       => 'datetime',
    ];

    public function returnOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
