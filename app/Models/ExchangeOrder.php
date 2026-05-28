<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeOrder extends Model
{
    use BelongsToShop;
    use ImmutableLedger;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SETTLED   = 'settled';
    public const STATUS_CANCELLED = 'cancelled';

    public const BASIS_SALE_DAY_RATE  = 'sale_day_rate';
    public const BASIS_TODAY_RATE     = 'today_rate';
    public const BASIS_FIXED_RATE     = 'fixed_rate';
    public const BASIS_MANUAL_OVERRIDE = 'manual_override';

    public const PAYMENT_CASH         = 'cash';
    public const PAYMENT_STORE_CREDIT = 'store_credit';
    public const PAYMENT_MIXED        = 'mixed';

    /**
     * Allow status + lifecycle transitions; everything else is immutable once
     * the row exists. ImmutableLedger applies hard-block on UPDATE for any
     * column outside this list — same accounting safety as ReturnOrder.
     */
    protected $allowedUpdateColumns = [
        'status', 'settled_at', 'settled_by_user_id',
        'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason',
        'approved_by_user_id', 'approved_at',
        'return_order_id', 'new_invoice_id', 'net_amount',
        'reason',
    ];

    protected $fillable = [
        'shop_id',
        'return_order_id', 'new_invoice_id', 'customer_id',
        'valuation_basis_source', 'valuation_rate_override',
        'net_amount', 'payment_method',
        'status', 'reason',
        'created_by_user_id',
        'settled_by_user_id', 'settled_at',
        'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason',
        'approved_by_user_id', 'approved_at',
    ];

    protected $casts = [
        'net_amount'              => 'decimal:2',
        'valuation_rate_override' => 'decimal:2',
        'settled_at'   => 'datetime',
        'cancelled_at' => 'datetime',
        'approved_at'  => 'datetime',
    ];

    public function returnOrder(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class);
    }

    public function newInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'new_invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_user_id');
    }
}
