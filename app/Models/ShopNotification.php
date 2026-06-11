<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopNotification extends Model
{
    use BelongsToShop; // global shop_id scope, same as Item/Customer

    protected $fillable = [
        'shop_id', 'recipient_user_id', 'type', 'counter_type',
        'actor_name', 'amount', 'customer_name', 'invoice_id',
        'invoice_type', 'read_at',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'read_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
