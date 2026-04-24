<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopMetalPurityProfile extends Model
{
    use BelongsToShop;

    protected $table = 'shop_metal_purity_profiles';

    protected $fillable = [
        'shop_id',
        'metal_type',
        'code',
        'label',
        'purity_value',
        'basis',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'purity_value' => 'decimal:3',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
