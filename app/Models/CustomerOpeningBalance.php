<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's opening MONEY balance, posted at batch lock. Read by the customer
 * ledger as a single "Opening Balance" line — never a fake invoice, so it never
 * touches sales/GST/profit.
 */
class CustomerOpeningBalance extends Model
{
    use BelongsToShop;

    public const DIRECTION_RECEIVABLE = 'receivable'; // customer owes shop
    public const DIRECTION_PAYABLE = 'payable';       // shop owes customer

    protected $fillable = [
        'shop_id',
        'customer_id',
        'onboarding_batch_id',
        'direction',
        'amount',
        'as_of_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'as_of_date' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
