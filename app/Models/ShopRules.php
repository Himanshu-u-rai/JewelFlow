<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopRules extends Model
{
    use BelongsToShop;

    protected $table = 'shop_rules';

    protected $fillable = [
        'default_purity',
        'default_making_type',
        'default_making_value',
        'test_loss_percent',
        'buyback_percent',
        'rounding_precision',
        // Per-category GST rates & wastage rounding
        'gst_rate_gold',
        'gst_rate_silver',
        'gst_rate_diamond',
        'wastage_rounding',
    ];

    protected $casts = [
        'default_making_value' => 'decimal:2',
        'test_loss_percent'    => 'decimal:2',
        'buyback_percent'      => 'decimal:2',
        'rounding_precision'   => 'integer',
        'gst_rate_gold'        => 'decimal:2',
        'gst_rate_silver'      => 'decimal:2',
        'gst_rate_diamond'     => 'decimal:2',
    ];

    protected $attributes = [
        'wastage_rounding' => '0.001',
    ];

    /**
     * Get the shop that owns these rules.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
