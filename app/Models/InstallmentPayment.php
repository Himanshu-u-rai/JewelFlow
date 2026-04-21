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
}
