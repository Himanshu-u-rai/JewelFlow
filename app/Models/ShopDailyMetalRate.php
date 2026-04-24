<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopDailyMetalRate extends Model
{
    use BelongsToShop;

    protected $table = 'shop_daily_metal_rates';

    public const CREATED_AT = 'entered_at';
    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'shop_id',
        'business_date',
        'timezone',
        'gold_24k_rate_per_gram',
        'silver_999_rate_per_gram',
        'entered_by_user_id',
        'entered_at',
        'updated_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'gold_24k_rate_per_gram' => 'decimal:4',
        'silver_999_rate_per_gram' => 'decimal:4',
        'entered_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }
}
