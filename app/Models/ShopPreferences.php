<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPreferences extends Model
{
    use BelongsToShop;

    protected $table = 'shop_preferences';

    protected $fillable = [
        'weight_unit',
        'date_format',
        'currency_symbol',
        'language',
        'low_stock_threshold',
        'round_off_nearest',
        'loyalty_points_per_hundred',
        'loyalty_point_value',
        'loyalty_expiry_months',
        'wa_custom_header',
        'wa_custom_body',
        'wa_custom_footer',
        // New
        'default_pricing_mode',
        'default_payment_mode',
        'auto_logout_minutes',
        'loyalty_welcome_bonus',
        'credit_days',
        'barcode_prefix',
    ];

    protected $casts = [
        'low_stock_threshold'        => 'integer',
        'round_off_nearest'          => 'integer',
        'loyalty_points_per_hundred' => 'integer',
        'loyalty_point_value'        => 'decimal:2',
        'loyalty_expiry_months'      => 'integer',
        'auto_logout_minutes'        => 'integer',
        'loyalty_welcome_bonus'      => 'integer',
        'credit_days'                => 'integer',
    ];

    protected $attributes = [
        'default_pricing_mode' => 'gst_exclusive',
        'default_payment_mode' => 'cash',
        'auto_logout_minutes'  => 0,
        'loyalty_welcome_bonus'=> 0,
        'credit_days'          => 0,
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
