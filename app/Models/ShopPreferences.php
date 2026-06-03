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
        'default_making_charge_type',
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
        // Rounding / discount policy (POS pricing engine)
        'rounding_method',
        'max_manual_discount_percent',
        'round_off_nearest',
        // Return / refund policy (consumed by Returns\* + RefundPolicyResolver)
        'refund_making_charges',
        'refund_stone_charges',
        'refund_hallmark_charges',
        'refund_gst',
        'wear_loss_pct',
        'restocking_fee_pct',
        'return_window_days',
        'exchange_window_days',
        'return_settlement_mode',
        'exchange_rate_basis_locked',
        'return_policy_configured_at',
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
        'max_manual_discount_percent'  => 'decimal:2',
        'round_off_nearest'            => 'integer',
        // Return / refund policy casts.
        'refund_making_charges'        => 'boolean',
        'refund_stone_charges'         => 'boolean',
        'refund_hallmark_charges'      => 'boolean',
        'refund_gst'                   => 'boolean',
        'wear_loss_pct'                => 'decimal:2',
        'restocking_fee_pct'           => 'decimal:2',
        'return_window_days'           => 'integer',
        'exchange_window_days'         => 'integer',
        'exchange_rate_basis_locked'   => 'boolean',
        'return_policy_configured_at'  => 'datetime',
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

    /**
     * Has the owner explicitly configured the return policy? Until they have,
     * the returns flow routes them to the settings tab first (zero-config would
     * otherwise refund 100% of everything). Referenced by Returns\* controllers.
     */
    public function hasConfiguredReturnPolicy(): bool
    {
        return ! is_null($this->return_policy_configured_at);
    }

    /**
     * Translate the boolean refund flags into the ValuationBasis $deductions
     * shape. Value semantics match ValuationBasis::isDeducted(): a `true` value
     * means the component is refundable (kept in the refund); absent defaults to
     * refundable (the zero-config "refund everything" behaviour). Consumed by
     * RefundPolicyResolver::basisFromPolicy() and ReturnService overrides.
     */
    public function toRefundDeductions(): array
    {
        return [
            'making_charges_refundable'   => (bool) ($this->refund_making_charges ?? true),
            'stone_charges_refundable'    => (bool) ($this->refund_stone_charges ?? true),
            'hallmark_charges_refundable' => (bool) ($this->refund_hallmark_charges ?? true),
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
