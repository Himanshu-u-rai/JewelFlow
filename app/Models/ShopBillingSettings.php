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
        'hsn_platinum',
        'hsn_copper',
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

    /**
     * Default Terms & Conditions shown on invoices / quick bills when a shop
     * hasn't set its own. Single source of truth — the print templates and the
     * settings form all read this so they never drift. Kept to 6 concise,
     * jewellery-specific points (within the 6-line / 600-char save limit).
     *
     * @return string[]
     */
    public static function defaultTerms(): array
    {
        return [
            'Goods are taken back or exchanged only as per store policy, with the original invoice.',
            'Making charges, GST, and hallmarking charges are non-refundable.',
            'Ornaments are hallmarked as per BIS standards; purity & HUID are as printed.',
            "Old gold/silver is valued at the day's rate after wastage & purity deductions.",
            'Please verify weight, purity & item details at the counter before leaving.',
            'All disputes are subject to local jurisdiction.',
        ];
    }

    /** System default HSN per metal — the fallback when a shop hasn't set one. */
    public const HSN_DEFAULTS = [
        'gold'     => '7113',
        'silver'   => '7113',
        'platinum' => '7115',
        'copper'   => '7403',
        'diamond'  => '7114',
    ];

    /**
     * The HSN code for a line, resolved from its metal type (preferred) with a
     * legacy category-substring fallback. Single source of truth used by the
     * print templates so HSN never drifts per metal. Returns the shop's
     * configured code for that metal, else the system default.
     *
     * Diamond/stone/gem lines have no metal_type, so they resolve via category.
     */
    public function hsnForMetal(?string $metalType, ?string $category = null): string
    {
        $metal = strtolower(trim((string) $metalType));
        $cat   = strtolower((string) $category);

        // Stone/diamond lines carry no metal type — key off the category.
        if ($metal === '' && ($cat !== '' && (str_contains($cat, 'diamond') || str_contains($cat, 'stone') || str_contains($cat, 'gem')))) {
            return $this->hsn_diamond ?: self::HSN_DEFAULTS['diamond'];
        }

        // Legacy lines with no metal_type: infer from the category string.
        if ($metal === '' && $cat !== '') {
            if (str_contains($cat, 'silver'))   $metal = 'silver';
            elseif (str_contains($cat, 'platinum')) $metal = 'platinum';
            elseif (str_contains($cat, 'copper'))   $metal = 'copper';
        }

        return match ($metal) {
            'silver'   => $this->hsn_silver   ?: self::HSN_DEFAULTS['silver'],
            'platinum' => $this->hsn_platinum ?: self::HSN_DEFAULTS['platinum'],
            'copper'   => $this->hsn_copper   ?: self::HSN_DEFAULTS['copper'],
            'diamond'  => $this->hsn_diamond  ?: self::HSN_DEFAULTS['diamond'],
            default    => $this->hsn_gold     ?: self::HSN_DEFAULTS['gold'],
        };
    }
}
