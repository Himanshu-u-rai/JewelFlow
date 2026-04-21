<?php

namespace App\Models\Platform;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionEvent extends Model
{
    protected $fillable = [
        'shop_subscription_id',
        'shop_id',
        'admin_id',
        'event_type',
        'before',
        'after',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ShopSubscription::class, 'shop_subscription_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }
}
