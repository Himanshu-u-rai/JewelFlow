<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\CanonicalisesMobileNumbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogWebsiteSettings extends Model
{
    use BelongsToShop, CanonicalisesMobileNumbers;

    /**
     * The public catalog's WhatsApp button. Stored bare like every other mobile
     * column; Mobile::forWhatsApp() puts the 91 back when the link is built.
     *
     * @var array<int, string>
     */
    protected static array $mobileColumns = ['social_whatsapp'];

    protected $table = 'catalog_website_settings';

    protected $fillable = [
        'shop_id',
        'is_enabled',
        'accent_color',
        'tagline',
        'hero_image_path',
        'hero_style',
        'hero_bg_color',
        'show_prices',
        'show_weights',
        'show_huid',
        'meta_description',
        'social_whatsapp',
        'social_instagram',
        'social_facebook',
        'featured_categories',
    ];

    protected $casts = [
        'is_enabled'          => 'boolean',
        'show_prices'         => 'boolean',
        'show_weights'        => 'boolean',
        'show_huid'           => 'boolean',
        'featured_categories' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
