<?php

namespace App\Models\Platform;

use App\Support\ShopEdition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The PLATFORM billing-product catalog.
 *
 * NOT to be confused with App\Models\Product, which is the TENANT jewellery
 * inventory catalog (shop item templates). This model lives in the platform
 * plane alongside Plan / ShopSubscription / PlatformInvoice and is NOT
 * tenant-scoped.
 *
 * Each product is a billable offering. A paid subscription to a plan whose
 * platform_product_id points here GRANTS the corresponding edition (see
 * editionString()), which is what actually controls in-app access.
 */
class PlatformProduct extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public const CODE_RETAIL         = 'retail';
    public const CODE_DHIRAN         = 'dhiran';
    public const CODE_MANUFACTURING  = 'manufacturing';
    public const CODE_CRM            = 'crm';
    public const CODE_ANALYTICS      = 'analytics';
    public const CODE_MOBILE_PREMIUM = 'mobile_premium';

    /**
     * The single source of truth for product.code ↔ edition string.
     *
     * Products use 'retail' / 'manufacturing'; the editions table (and the 15+
     * shipped call-sites) use 'retailer' / 'manufacturer' / 'dhiran'. This is the
     * ONE place that reconciles the two — never rename the existing edition
     * strings, just translate here.
     *
     *   retail         → retailer
     *   manufacturing  → manufacturer
     *   dhiran         → dhiran
     *   crm            → crm
     *   analytics      → analytics
     *   mobile_premium → mobile_premium
     */
    public static function editionStringFor(string $productCode): string
    {
        return match ($productCode) {
            self::CODE_RETAIL        => ShopEdition::RETAILER,
            self::CODE_MANUFACTURING => ShopEdition::MANUFACTURER,
            self::CODE_DHIRAN        => ShopEdition::DHIRAN,
            default                  => $productCode, // crm / analytics / mobile_premium map 1:1
        };
    }

    /**
     * Instance shortcut for editionStringFor($this->code).
     */
    public function editionString(): string
    {
        return self::editionStringFor($this->code);
    }

    /**
     * Reverse mapping: edition string → product code. Used when wiring an
     * existing edition back to the product that should bill for it.
     */
    public static function codeForEdition(string $edition): string
    {
        return match ($edition) {
            ShopEdition::RETAILER     => self::CODE_RETAIL,
            ShopEdition::MANUFACTURER => self::CODE_MANUFACTURING,
            ShopEdition::DHIRAN       => self::CODE_DHIRAN,
            default                   => $edition,
        };
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class, 'platform_product_id');
    }

    public function scopeActive($query)
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS TRUE');
    }
}
