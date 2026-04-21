<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopBillingSettings extends Model
{
    use BelongsToShop;

    protected $table = 'shop_billing_settings';

    protected $fillable = [
        // Existing
        'invoice_prefix',
        'invoice_start_number',
        'terms_and_conditions',
        'bank_details',
        'bank_name',
        'bank_account_number',
        'bank_ifsc',
        'bank_account_type',
        'bank_account_holder',
        'bank_branch',
        'upi_id',
        'digital_signature_path',
        'show_digital_signature',
        'show_bis_logo',
        // Branding
        'theme_color',
        'font_size',
        'shop_subtitle',
        'custom_tagline',
        // Invoice copy
        'invoice_copy_label',
        'copy_count',
        // Invoice number
        'invoice_suffix',
        'year_reset',
        'current_fiscal_year',
        // Column visibility
        'show_huid',
        'show_stone_columns',
        'show_purity',
        'show_gstin',
        'show_customer_address',
        'show_customer_id_pan',
        // Tax
        'igst_mode',
        'hsn_gold',
        'hsn_silver',
        'hsn_diamond',
        // Footer / print
        'second_signature_label',
        'paper_size',
    ];

    protected $casts = [
        'invoice_start_number'    => 'integer',
        'copy_count'              => 'integer',
        'show_digital_signature'  => 'boolean',
        'show_bis_logo'           => 'boolean',
        'year_reset'              => 'boolean',
        'show_huid'               => 'boolean',
        'show_stone_columns'      => 'boolean',
        'show_purity'             => 'boolean',
        'show_gstin'              => 'boolean',
        'show_customer_address'   => 'boolean',
        'show_customer_id_pan'    => 'boolean',
        'igst_mode'               => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
