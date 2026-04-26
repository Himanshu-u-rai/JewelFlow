<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickBillPayment extends Model
{
    use BelongsToShop;

    protected $guarded = ['*'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function quickBill(): BelongsTo
    {
        return $this->belongsTo(QuickBill::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(ShopPaymentMethod::class, 'payment_method_id');
    }
}
