<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class InstallmentPayment extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'plan_id',
        'amount',
        'payment_date',
        'payment_method',
        'payment_method_id',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function plan()
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(ShopPaymentMethod::class, 'payment_method_id');
    }
}
