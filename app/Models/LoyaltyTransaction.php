<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'customer_id',
        'invoice_id',
        'type',
        'points',
        'description',
        'balance_after',
        'expires_at',
        'expired',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
        'expires_at' => 'datetime',
        'expired' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeEarns($query)
    {
        return $query->where('type', 'earn');
    }

    public function scopeRedemptions($query)
    {
        return $query->where('type', 'redeem');
    }
}
