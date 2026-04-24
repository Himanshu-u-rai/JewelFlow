<?php

namespace App\Models\Platform;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopSubscription extends Model
{
    protected $fillable = [
        'shop_id',
        'user_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
        'grace_ends_at',
        'cancelled_at',
        'billing_cycle',
        'price_paid',
        'razorpay_payment_id',
        'razorpay_order_id',
        'updated_by_admin_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'grace_ends_at' => 'date',
            'cancelled_at' => 'datetime',
            'price_paid' => 'decimal:2',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'updated_by_admin_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class);
    }
}
