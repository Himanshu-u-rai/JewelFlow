<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogWebsiteSettings extends Model
{
    use BelongsToShop;

    protected $table = 'catalog_website_settings';

    protected $fillable = [
        'shop_id',
        'is_enabled',
        'accent_color',
        'tagline',
        'hero_image_path',
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
