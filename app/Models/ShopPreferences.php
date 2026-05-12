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
        'pricing_timezone',
        'low_stock_threshold',
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
        'stock_value_display',
        // Compliance
        'compliance_enabled',
        'compliance_threshold',
        'compliance_pan_mandatory',
        'compliance_mobile_mandatory',
        'compliance_address_mandatory',
    ];

    protected $casts = [
        'low_stock_threshold'        => 'integer',
        'loyalty_points_per_hundred' => 'integer',
        'loyalty_point_value'        => 'decimal:2',
        'loyalty_expiry_months'      => 'integer',
        'auto_logout_minutes'          => 'integer',
        'loyalty_welcome_bonus'        => 'integer',
        'credit_days'                  => 'integer',
        'compliance_enabled'           => 'boolean',
        'compliance_threshold'         => 'decimal:2',
        'compliance_pan_mandatory'     => 'boolean',
        'compliance_mobile_mandatory'  => 'boolean',
        'compliance_address_mandatory' => 'boolean',
    ];

    protected $attributes = [
        'default_pricing_mode' => 'gst_exclusive',
        'default_payment_mode' => 'cash',
        'auto_logout_minutes'  => 0,
        'loyalty_welcome_bonus'=> 0,
        'credit_days'          => 0,
        'pricing_timezone'      => 'UTC',
        'stock_value_display'   => 'total',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
